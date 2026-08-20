<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Domain\CloudDrive\DTO\TransferResult;
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

class CrossDriveTransferTest extends PackageTestCase
{
    private MockInterface $oauth;
    private MockInterface $files;
    private MockInterface $storage;
    private ConnectedDriveService $service;
    private Workspace $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauth   = Mockery::mock(OAuthService::class);
        $this->files   = Mockery::mock(FileSystemContract::class);
        $this->storage = Mockery::mock(StorageService::class);

        $this->workspace = Workspace::create(['name' => 'Corp', 'uuid' => Str::uuid()]);
    }

    // ---------------------------------------------------------------
    // copyFile — cross-drive (download + upload)
    // ---------------------------------------------------------------

    public function test_copy_file_cross_drive_downloads_then_uploads(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $meta   = $this->file('file-1', 'report.pdf', 'application/pdf', 1024);
        $copied = $this->file('file-2', 'report.pdf', 'application/pdf', 1024);

        $fromAdapter->shouldReceive('getMetadata')->with('file-1')->once()->andReturn($meta);
        $fromAdapter->shouldReceive('getFileBinary')->with('file-1')->once()->andReturn('pdf bytes');
        $toAdapter->shouldReceive('putFile')->once()->andReturn($copied);

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->copyFile($this->workspace, $from, 'file-1', $to, 'root');

        $this->assertSame('file-2', $result->id);
    }

    public function test_copy_file_cross_drive_uses_custom_name(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $meta   = $this->file('file-1', 'original.pdf', 'application/pdf', 512);
        $copied = $this->file('file-3', 'renamed.pdf', 'application/pdf', 512);

        $fromAdapter->shouldReceive('getMetadata')->andReturn($meta);
        $fromAdapter->shouldReceive('getFileBinary')->andReturn('bytes');
        $toAdapter->shouldReceive('putFile')
            ->withArgs(fn ($fid, $name) => $name === 'renamed.pdf')
            ->once()
            ->andReturn($copied);

        $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->copyFile($this->workspace, $from, 'file-1', $to, 'root', 'renamed.pdf');

        Mockery::close(); // assertions above are the point
        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // copyFile — same drive (native)
    // ---------------------------------------------------------------

    public function test_copy_file_same_drive_uses_native_method(): void
    {
        [$drive, $adapter] = $this->oneDrive();

        $meta   = $this->file('orig-id', 'photo.jpg', 'image/jpeg', 2048);
        $copied = $this->file('copy-id', 'photo.jpg', 'image/jpeg', 2048);

        $adapter->shouldReceive('getMetadata')->with('orig-id')->once()->andReturn($meta);
        $adapter->shouldReceive('copyFileSameDrive')
            ->with('orig-id', 'folder-x', 'photo.jpg')
            ->once()
            ->andReturn($copied);
        // Must NOT call getFileBinary or putFile
        $adapter->shouldNotReceive('getFileBinary');
        $adapter->shouldNotReceive('putFile');

        $result = $this->makeService($adapter, $adapter)
            ->copyFile($this->workspace, $drive, 'orig-id', $drive, 'folder-x');

        $this->assertSame('copy-id', $result->id);
    }

    // ---------------------------------------------------------------
    // moveFile — same drive (native)
    // ---------------------------------------------------------------

    public function test_move_file_same_drive_uses_native_move(): void
    {
        [$drive, $adapter] = $this->oneDrive();

        $meta  = $this->file('orig-id', 'doc.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 512);
        $moved = $this->file('orig-id', 'doc.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 512);

        $adapter->shouldReceive('getMetadata')->with('orig-id')->once()->andReturn($meta);
        $adapter->shouldReceive('moveFileSameDrive')
            ->with('orig-id', 'target-folder', 'doc.docx')
            ->once()
            ->andReturn($moved);
        $adapter->shouldNotReceive('deleteFile');

        $this->makeService($adapter, $adapter)
            ->moveFile($this->workspace, $drive, 'orig-id', $drive, 'target-folder');

        $this->assertTrue(true);
    }

    // ---------------------------------------------------------------
    // moveFile — cross-drive (copy + delete)
    // ---------------------------------------------------------------

    public function test_move_file_cross_drive_copies_then_deletes_source(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $meta   = $this->file('src-id', 'file.txt', 'text/plain', 100);
        $copied = $this->file('dst-id', 'file.txt', 'text/plain', 100);

        $fromAdapter->shouldReceive('getMetadata')->andReturn($meta);
        $fromAdapter->shouldReceive('getFileBinary')->andReturn('text content');
        $toAdapter->shouldReceive('putFile')->andReturn($copied);
        $fromAdapter->shouldReceive('deleteFile')->with('src-id')->once();

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->moveFile($this->workspace, $from, 'src-id', $to, 'root');

        $this->assertSame('dst-id', $result->id);
    }

    // ---------------------------------------------------------------
    // copyFolder — recursive
    // ---------------------------------------------------------------

    public function test_copy_folder_creates_structure_on_target(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $rootMeta = $this->folder('src-root', 'Documents');
        $fileMeta = $this->file('src-file', 'readme.md', 'text/markdown', 200);
        $subMeta  = $this->folder('src-sub', 'Sub');

        // Source: root folder has one file and one subfolder
        $fromAdapter->shouldReceive('getMetadata')->with('src-root')->andReturn($rootMeta);
        $fromAdapter->shouldReceive('listFolder')->with('src-root')->andReturn([$fileMeta, $subMeta]);
        $fromAdapter->shouldReceive('listFolder')->with('src-sub')->andReturn([]);   // empty subfolder
        $fromAdapter->shouldReceive('getFileBinary')->with('src-file')->once()->andReturn('# readme');

        // Target: create root folder, then file, then subfolder
        $toAdapter->shouldReceive('createFolder')
            ->with('root', 'Documents')
            ->once()
            ->andReturn($this->folder('dst-root', 'Documents'));

        $toAdapter->shouldReceive('putFile')
            ->with('dst-root', 'readme.md', '# readme', 'text/markdown')
            ->once()
            ->andReturn($fileMeta);

        $toAdapter->shouldReceive('createFolder')
            ->with('dst-root', 'Sub')
            ->once()
            ->andReturn($this->folder('dst-sub', 'Sub'));

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->copyFolder($this->workspace, $from, 'src-root', $to, 'root');

        $this->assertInstanceOf(TransferResult::class, $result);
        $this->assertSame(1, $result->filesCopied);
        $this->assertSame(2, $result->foldersCreated); // root + subfolder
        $this->assertFalse($result->hasErrors());
    }

    public function test_copy_folder_records_per_file_errors(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $rootMeta = $this->folder('src-root', 'Docs');
        $badFile  = $this->file('bad-id', 'corrupt.bin', 'application/octet-stream', 0);

        $fromAdapter->shouldReceive('getMetadata')->with('src-root')->andReturn($rootMeta);
        $fromAdapter->shouldReceive('listFolder')->with('src-root')->andReturn([$badFile]);
        $fromAdapter->shouldReceive('getFileBinary')
            ->with('bad-id')
            ->andThrow(new \RuntimeException('Download failed'));

        $toAdapter->shouldReceive('createFolder')->andReturn($this->folder('dst-root', 'Docs'));

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->copyFolder($this->workspace, $from, 'src-root', $to, 'root');

        $this->assertSame(0, $result->filesCopied);
        $this->assertTrue($result->hasErrors());
        $this->assertStringContainsString('corrupt.bin', $result->errors[0]['path']);
    }

    // ---------------------------------------------------------------
    // moveFolder — copies then deletes source on success
    // ---------------------------------------------------------------

    public function test_move_folder_deletes_source_after_successful_copy(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $rootMeta = $this->folder('src-root', 'Archive');

        $fromAdapter->shouldReceive('getMetadata')->with('src-root')->andReturn($rootMeta);
        $fromAdapter->shouldReceive('listFolder')->with('src-root')->andReturn([]);
        $toAdapter->shouldReceive('createFolder')->andReturn($this->folder('dst-root', 'Archive'));
        $fromAdapter->shouldReceive('deleteFile')->with('src-root')->once();

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->moveFolder($this->workspace, $from, 'src-root', $to, 'root');

        $this->assertFalse($result->hasErrors());
    }

    public function test_move_folder_does_not_delete_source_when_errors_exist(): void
    {
        [$from, $to, $fromAdapter, $toAdapter] = $this->twoSeparateDrives();

        $rootMeta = $this->folder('src-root', 'Important');
        $badFile  = $this->file('f1', 'data.csv', 'text/csv', 100);

        $fromAdapter->shouldReceive('getMetadata')->with('src-root')->andReturn($rootMeta);
        $fromAdapter->shouldReceive('listFolder')->andReturn([$badFile]);
        $fromAdapter->shouldReceive('getFileBinary')->andThrow(new \RuntimeException('FS error'));
        $toAdapter->shouldReceive('createFolder')->andReturn($this->folder('dst-root', 'Important'));
        $fromAdapter->shouldNotReceive('deleteFile');

        $result = $this->makeService($fromAdapter, $toAdapter, $from, $to)
            ->moveFolder($this->workspace, $from, 'src-root', $to, 'root');

        $this->assertTrue($result->hasErrors());
    }

    // ---------------------------------------------------------------
    // TransferResult serialisation
    // ---------------------------------------------------------------

    public function test_transfer_result_to_array(): void
    {
        $root   = $this->folder('id', 'Folder');
        $result = new TransferResult($root, filesCopied: 3, foldersCreated: 2, errors: []);

        $arr = $result->toArray();

        $this->assertSame(3, $arr['files_copied']);
        $this->assertSame(2, $arr['folders_created']);
        $this->assertFalse($arr['has_errors']);
        $this->assertArrayHasKey('root', $arr);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** Returns a combined CloudAdapter + SupportsSameDriveOperations mock */
    private function nativeAdapter(): MockInterface
    {
        return Mockery::mock(CloudAdapter::class, SupportsSameDriveOperations::class);
    }

    /** Returns a plain CloudAdapter mock (no native same-drive support) */
    private function basicAdapter(): MockInterface
    {
        return Mockery::mock(CloudAdapter::class);
    }

    private function makeDrive(string $name = 'Drive'): ConnectedDrive
    {
        return ConnectedDrive::create([
            'uuid'      => Str::uuid(),
            'workspace_id' => $this->workspace->id,
            'provider'  => CloudProvider::GOOGLE_DRIVE,
            'name'      => $name,
            'status'    => ConnectedDriveStatus::CONNECTED,
        ]);
    }

    /** Two drives with their mocked adapters: [$from, $to, $fromAdapter, $toAdapter] */
    private function twoSeparateDrives(): array
    {
        $from        = $this->makeDrive('From');
        $to          = $this->makeDrive('To');
        $fromAdapter = $this->basicAdapter();
        $toAdapter   = $this->basicAdapter();

        return [$from, $to, $fromAdapter, $toAdapter];
    }

    /** A single drive with a native-capable adapter: [$drive, $adapter] */
    private function oneDrive(): array
    {
        $drive   = $this->makeDrive('Single');
        $adapter = $this->nativeAdapter();

        return [$drive, $adapter];
    }

    /**
     * Build a partial ConnectedDriveService where adapterFor() is mapped by drive ID.
     * Pass the same adapter for both $from and $to for same-drive scenarios.
     */
    private function makeService(
        MockInterface  $fromAdapter,
        MockInterface  $toAdapter,
        ConnectedDrive $fromDrive = null,
        ConnectedDrive $toDrive   = null,
    ): ConnectedDriveService {
        $svc = Mockery::mock(
            ConnectedDriveService::class,
            [$this->oauth, $this->files, $this->storage]
        )->makePartial()->shouldAllowMockingProtectedMethods();

        $svc->shouldReceive('adapterFor')
            ->andReturnUsing(function (ConnectedDrive $drive) use ($fromAdapter, $toAdapter, $fromDrive) {
                // Same mock for same-drive scenario
                if ($fromAdapter === $toAdapter) {
                    return $fromAdapter;
                }
                // Match by drive ID when drive models are available
                if ($fromDrive !== null && $drive->id === $fromDrive->id) {
                    return $fromAdapter;
                }
                return $toAdapter;
            });

        return $svc;
    }

    private function file(string $id, string $name, string $mime, int $size): CloudFile
    {
        return new CloudFile($id, $name, false, $size, $mime, null, null, null);
    }

    private function folder(string $id, string $name): CloudFile
    {
        return new CloudFile($id, $name, true, null, null, null, null, null);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
