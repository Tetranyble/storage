<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\DownloadService;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;
use RuntimeException;

class DownloadServiceTest extends PackageTestCase
{
    private MockInterface $files;
    private MockInterface $drives;
    private MockInterface $access;
    private DownloadService $service;
    private Workspace $workspace;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files  = Mockery::mock(FileSystemContract::class);
        $this->drives = Mockery::mock(ConnectedDriveService::class);
        $this->access = Mockery::mock(ResourceAccessControl::class);

        $this->service = new DownloadService($this->files, $this->drives, $this->access);

        $this->workspace = Workspace::create(['name' => 'Acme', 'uuid' => Str::uuid()]);
        $this->actor  = User::create(['name' => 'Alice', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);
    }

    // ---------------------------------------------------------------
    // downloadMedia — local files
    // ---------------------------------------------------------------

    public function test_download_workspace_scoped_media_streams_binary(): void
    {
        $media = $this->mediaRecord('report.pdf', AccessScope::WORKSPACE, 'application/pdf');

        $this->files->shouldReceive('get')
            ->with($media->path, Disk::PUBLIC)
            ->once()
            ->andReturn('%PDF bytes');

        $response = $this->service->downloadMedia($this->workspace, $media, $this->actor);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('report.pdf', $response->headers->get('Content-Disposition'));
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_download_workspace_media_without_actor_aborts_401(): void
    {
        $media = $this->mediaRecord('doc.pdf', AccessScope::WORKSPACE);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->downloadMedia($this->workspace, $media, null);
    }

    public function test_download_restricted_media_calls_authorizeView(): void
    {
        $media = $this->mediaRecord('secret.pdf', AccessScope::RESTRICTED, 'application/pdf');

        $this->access->shouldReceive('authorizeView')
            ->with($this->workspace, $media, $this->actor)
            ->once();

        $this->files->shouldReceive('get')->andReturn('bytes');

        $response = $this->service->downloadMedia($this->workspace, $media, $this->actor);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_download_wrong_workspace_aborts_404(): void
    {
        $otherWorkspace = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $media       = $this->mediaRecord('file.pdf', AccessScope::WORKSPACE, 'application/pdf', $otherWorkspace);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->downloadMedia($this->workspace, $media, $this->actor);
    }

    // ---------------------------------------------------------------
    // zipMedia — local files
    // ---------------------------------------------------------------

    public function test_zip_media_includes_viewable_items(): void
    {
        $m1 = $this->mediaRecord('a.txt', AccessScope::WORKSPACE, 'text/plain');
        $m2 = $this->mediaRecord('b.txt', AccessScope::WORKSPACE, 'text/plain');

        $this->files->shouldReceive('get')->twice()->andReturn('content');

        $result = $this->service->zipMedia($this->workspace, [$m1, $m2], $this->actor, 'bundle');

        $this->assertSame(2, $result['zipped']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(200, $result['response']->getStatusCode());
        $this->assertStringContainsString('bundle.zip', $result['response']->headers->get('Content-Disposition'));
    }

    public function test_zip_media_skips_restricted_items_without_permission(): void
    {
        $allowed   = $this->mediaRecord('ok.txt',  AccessScope::WORKSPACE, 'text/plain');
        $forbidden = $this->mediaRecord('no.txt',  AccessScope::RESTRICTED, 'text/plain');

        $this->access->shouldReceive('canView')
            ->with($this->workspace, $forbidden, $this->actor)
            ->once()
            ->andReturn(false);

        $this->files->shouldReceive('get')->once()->andReturn('ok content');

        $result = $this->service->zipMedia($this->workspace, [$allowed, $forbidden], $this->actor);

        $this->assertSame(1, $result['zipped']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_zip_media_skips_wrong_workspace_items(): void
    {
        $other     = Workspace::create(['name' => 'X', 'uuid' => Str::uuid()]);
        $goodMedia = $this->mediaRecord('good.txt', AccessScope::WORKSPACE, 'text/plain');
        $badMedia  = $this->mediaRecord('bad.txt',  AccessScope::WORKSPACE, 'text/plain', $other);

        $this->files->shouldReceive('get')->once()->andReturn('data');

        $result = $this->service->zipMedia($this->workspace, [$goodMedia, $badMedia], $this->actor);

        $this->assertSame(1, $result['zipped']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_zip_media_skips_items_that_throw_on_read(): void
    {
        $m = $this->mediaRecord('broken.txt', AccessScope::WORKSPACE, 'text/plain');

        $this->files->shouldReceive('get')->once()->andThrow(new RuntimeException('disk error'));

        $result = $this->service->zipMedia($this->workspace, [$m], $this->actor);

        $this->assertSame(0, $result['zipped']);
        $this->assertSame(1, $result['skipped']);
    }

    public function test_zip_media_deduplicates_filenames_in_archive(): void
    {
        // Two files with identical original_name but different paths
        $m1 = $this->mediaRecord('report.pdf', AccessScope::WORKSPACE, 'application/pdf');
        $m2 = $this->mediaRecord('report.pdf', AccessScope::WORKSPACE, 'application/pdf');

        $this->files->shouldReceive('get')->twice()->andReturn('pdf');

        $result = $this->service->zipMedia($this->workspace, [$m1, $m2], $this->actor);

        $this->assertSame(2, $result['zipped']);
    }

    // ---------------------------------------------------------------
    // downloadFromDrive — cloud
    // ---------------------------------------------------------------

    public function test_download_from_drive_returns_streamed_response(): void
    {
        $drive   = $this->drive();
        $adapter = Mockery::mock(\Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter::class);
        $meta    = new CloudFile('f1', 'photo.jpg', false, 512, 'image/jpeg', null, null, null);

        $this->drives->shouldReceive('adapterFor')->with($drive)->andReturn($adapter);
        $adapter->shouldReceive('getMetadata')->with('f1')->andReturn($meta);
        $adapter->shouldReceive('getFileBinary')->with('f1')->andReturn('jpeg bytes');

        $response = $this->service->downloadFromDrive($this->workspace, $drive, 'f1');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('photo.jpg', $response->headers->get('Content-Disposition'));
    }

    // ---------------------------------------------------------------
    // zipFromDrive — cloud, flat files + recursive folder
    // ---------------------------------------------------------------

    public function test_zip_from_drive_flat_files(): void
    {
        $drive   = $this->drive();
        $adapter = Mockery::mock(\Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter::class);

        $f1 = new CloudFile('id1', 'a.txt', false, 10, 'text/plain', null, null, null);
        $f2 = new CloudFile('id2', 'b.txt', false, 10, 'text/plain', null, null, null);

        $this->drives->shouldReceive('adapterFor')->andReturn($adapter);
        $adapter->shouldReceive('getMetadata')->with('id1')->andReturn($f1);
        $adapter->shouldReceive('getMetadata')->with('id2')->andReturn($f2);
        $adapter->shouldReceive('getFileBinary')->with('id1')->andReturn('aaa');
        $adapter->shouldReceive('getFileBinary')->with('id2')->andReturn('bbb');

        $result = $this->service->zipFromDrive($this->workspace, $drive, ['id1', 'id2'], 'bundle');

        $this->assertSame(2, $result['zipped']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(200, $result['response']->getStatusCode());
    }

    public function test_zip_from_drive_recurses_into_folders(): void
    {
        $drive   = $this->drive();
        $adapter = Mockery::mock(\Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter::class);

        $folder  = new CloudFile('dir1', 'Docs', true, null, null, null, null, null);
        $file    = new CloudFile('fid1', 'readme.md', false, 100, 'text/markdown', null, null, null);

        $this->drives->shouldReceive('adapterFor')->andReturn($adapter);
        $adapter->shouldReceive('getMetadata')->with('dir1')->andReturn($folder);
        $adapter->shouldReceive('listFolder')->with('dir1')->andReturn([$file]);
        $adapter->shouldReceive('getFileBinary')->with('fid1')->andReturn('# readme');

        $result = $this->service->zipFromDrive($this->workspace, $drive, ['dir1']);

        // addFolderToZip returns the count of files it added (1 in this case)
        $this->assertSame(1, $result['zipped']);
        $this->assertSame(0, $result['skipped']);
    }

    public function test_zip_from_drive_skips_failed_items(): void
    {
        $drive   = $this->drive();
        $adapter = Mockery::mock(\Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter::class);

        $this->drives->shouldReceive('adapterFor')->andReturn($adapter);
        $adapter->shouldReceive('getMetadata')->andThrow(new RuntimeException('not found'));

        $result = $this->service->zipFromDrive($this->workspace, $drive, ['bad-id']);

        $this->assertSame(0, $result['zipped']);
        $this->assertSame(1, $result['skipped']);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function mediaRecord(
        string      $filename,
        AccessScope $scope,
        string      $mime = 'application/octet-stream',
        ?Workspace     $workspace = null,
    ): Media {
        $t = $workspace ?? $this->workspace;

        return Media::create([
            'uuid'          => Str::uuid(),
            'workspace_id'     => $t->id,
            'original_name' => $filename,
            'path'          => 'files/'.$filename,
            'disk'          => 'public',
            'access_scope'  => $scope->value,
            'mime_type'     => $mime,
            'status'        => 'READY',
        ]);
    }

    private function drive(): ConnectedDrive
    {
        return ConnectedDrive::create([
            'uuid'      => Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'provider'  => CloudProvider::GOOGLE_DRIVE,
            'name'      => 'My Drive',
            'status'    => ConnectedDriveStatus::CONNECTED,
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
