<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;

class ConnectedDriveServiceTest extends PackageTestCase
{
    private MockInterface $oauth;
    private MockInterface $files;
    private MockInterface $storage;
    private ConnectedDriveService $service;
    private Workspace $workspace;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauth   = Mockery::mock(OAuthService::class);
        $this->files   = Mockery::mock(FileSystemContract::class);
        $this->storage = Mockery::mock(StorageService::class);

        $this->service = new ConnectedDriveService($this->oauth, $this->files, $this->storage);

        $this->workspace = Workspace::create(['name' => 'Acme Corp', 'uuid' => \Illuminate\Support\Str::uuid()]);
        $this->user   = User::create(['name' => 'Alice', 'uuid' => \Illuminate\Support\Str::uuid(), 'workspace_id' => $this->workspace->id]);

        Event::fake();
    }

    public function test_connect_oauth_creates_connected_drive(): void
    {
        $tokenData = [
            'access_token'  => 'goog-token',
            'refresh_token' => 'goog-refresh',
            'expires_at'    => Carbon::now()->addHour(),
        ];

        $drive = $this->service->connectOAuth($this->workspace, CloudProvider::GOOGLE_DRIVE, $tokenData, 'My GDrive');

        $this->assertInstanceOf(ConnectedDrive::class, $drive);
        $this->assertSame(CloudProvider::GOOGLE_DRIVE, $drive->provider);
        $this->assertSame('My GDrive', $drive->name);
        $this->assertSame(ConnectedDriveStatus::CONNECTED, $drive->status);
        $this->assertDatabaseHas('connected_drives', ['workspace_id' => $this->workspace->id, 'name' => 'My GDrive']);
    }

    public function test_connect_oauth_throws_for_s3(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->connectOAuth($this->workspace, CloudProvider::S3, [], 'My S3');
    }

    public function test_disconnect_soft_deletes_drive(): void
    {
        $drive = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);

        $this->service->disconnect($this->workspace, $drive, $this->user);

        $this->assertSoftDeleted('connected_drives', ['id' => $drive->id]);
    }

    public function test_disconnect_fires_event(): void
    {
        $drive = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);

        $this->service->disconnect($this->workspace, $drive, $this->user);

        Event::assertDispatched(\Tetranyble\Storage\Events\DriveDisconnected::class);
    }

    public function test_disconnect_aborts_for_wrong_workspace(): void
    {
        $other = Workspace::create(['name' => 'Other', 'uuid' => \Illuminate\Support\Str::uuid()]);
        $drive = ConnectedDrive::create([
            'uuid'      => \Illuminate\Support\Str::uuid(),
            'workspace_id' => $other->id,
            'provider'  => CloudProvider::GOOGLE_DRIVE,
            'name'      => 'Drive',
            'status'    => ConnectedDriveStatus::CONNECTED,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->disconnect($this->workspace, $drive, $this->user);
    }

    public function test_list_connected_returns_only_workspace_drives(): void
    {
        $this->makeDrive(CloudProvider::GOOGLE_DRIVE, 'GDrive');
        $this->makeDrive(CloudProvider::ONEDRIVE, 'OneDrive');

        $other = Workspace::create(['name' => 'Other', 'uuid' => \Illuminate\Support\Str::uuid()]);
        ConnectedDrive::create(['uuid' => \Illuminate\Support\Str::uuid(), 'workspace_id' => $other->id, 'provider' => CloudProvider::S3, 'name' => 'Other S3', 'status' => ConnectedDriveStatus::CONNECTED]);

        $list = $this->service->listConnected($this->workspace);

        $this->assertCount(2, $list);
        $this->assertTrue($list->every(fn ($d) => (int) $d->workspace_id === (int) $this->workspace->id));
    }

    public function test_browse_folder_returns_structured_response(): void
    {
        $drive   = $this->makeDrive(CloudProvider::GOOGLE_DRIVE);
        $adapter = Mockery::mock(CloudAdapter::class);
        $cloudFile = new CloudFile('f1', 'report.pdf', false, 1024, 'application/pdf', null, null, null);
        $adapter->shouldReceive('listFolder')->with('root')->andReturn([$cloudFile]);

        // Inject a pre-built adapter into the service (bypass adapterFor factory)
        $service = Mockery::mock(ConnectedDriveService::class, [$this->oauth, $this->files, $this->storage])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $result = $service->browseFolder($this->workspace, $drive, 'root');

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('drive', $result);
        $this->assertCount(1, $result['items']);
        $this->assertSame('report.pdf', $result['items'][0]['name']);
    }

    public function test_export_file_calls_put_file_on_adapter(): void
    {
        $drive  = $this->makeDrive(CloudProvider::S3);
        $folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name'      => 'Root',
            'slug'      => 'root',
            'path'      => '/',
            'uuid'      => \Illuminate\Support\Str::uuid(),
        ]);
        $media = Media::create([
            'workspace_id'     => $this->workspace->id,
            'folder_id'     => $folder->id,
            'uuid'          => \Illuminate\Support\Str::uuid(),
            'disk'          => Disk::PUBLIC,
            'path'          => 'test/file.pdf',
            'original_name' => 'report.pdf',
            'mime_type'     => 'application/pdf',
        ]);

        $adapter   = Mockery::mock(CloudAdapter::class);
        $cloudFile = new CloudFile('remote-id', 'report.pdf', false, 100, 'application/pdf', null, null, null);
        $adapter->shouldReceive('putFile')->once()->andReturn($cloudFile);

        $this->files->shouldReceive('get')->once()->andReturn('pdf binary content');

        $service = Mockery::mock(ConnectedDriveService::class, [$this->oauth, $this->files, $this->storage])
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();
        $service->shouldReceive('adapterFor')->andReturn($adapter);

        $result = $service->exportFile($this->workspace, $media, $drive, 'root');

        $this->assertSame('remote-id', $result->id);
    }

    // ---------------------------------------------------------------
    // Local drive
    // ---------------------------------------------------------------

    public function test_connect_local_creates_drive_with_disk_name(): void
    {
        $drive = $this->service->connectLocal($this->workspace, 'local', 'My Local Disk');

        $this->assertInstanceOf(ConnectedDrive::class, $drive);
        $this->assertSame(CloudProvider::LOCAL, $drive->provider);
        $this->assertSame('My Local Disk', $drive->name);
        $this->assertSame('local', $drive->credentials['disk']);
        $this->assertSame(ConnectedDriveStatus::CONNECTED, $drive->status);
        $this->assertTrue((bool) $drive->is_default);
    }

    public function test_connect_local_public_disk_is_supported(): void
    {
        $drive = $this->service->connectLocal($this->workspace, 'public', 'Public Files');

        $this->assertSame('public', $drive->credentials['disk']);
        $this->assertSame(CloudProvider::LOCAL, $drive->provider);
    }

    public function test_connect_local_second_drive_is_not_default(): void
    {
        $this->service->connectLocal($this->workspace, 'local', 'Private');
        $second = $this->service->connectLocal($this->workspace, 'public', 'Public');

        $this->assertFalse((bool) $second->is_default);
    }

    public function test_connect_local_fires_drive_connected_event(): void
    {
        $this->service->connectLocal($this->workspace, 'local', 'My Local');

        Event::assertDispatched(\Tetranyble\Storage\Events\DriveConnected::class);
    }

    public function test_connect_oauth_throws_for_local(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->service->connectOAuth($this->workspace, CloudProvider::LOCAL, [], 'Local');
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeDrive(CloudProvider $provider, string $name = 'Test Drive'): ConnectedDrive
    {
        return ConnectedDrive::create([
            'uuid'         => \Illuminate\Support\Str::uuid(),
            'workspace_id'    => $this->workspace->id,
            'provider'     => $provider,
            'name'         => $name,
            'access_token' => 'fake-token',
            'status'       => ConnectedDriveStatus::CONNECTED,
            'connected_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
