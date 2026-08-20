<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\GoogleDriveAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Mockery;
use Mockery\MockInterface;

class GoogleDriveAdapterTest extends PackageTestCase
{
    private MockInterface $driveService;
    private MockInterface $filesResource;
    private GoogleDriveAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock the Drive service and its files resource
        $this->filesResource = Mockery::mock(Drive\Resource\Files::class);
        $this->driveService  = Mockery::mock(Drive::class);
        $this->driveService->files = $this->filesResource;

        // Inject via reflection to avoid needing real Google OAuth
        $this->adapter = $this->makeAdapter();
        $this->injectDriveService($this->adapter, $this->driveService);
    }

    public function test_list_folder_returns_cloud_files(): void
    {
        $file1 = $this->makeFile('file-1', 'document.pdf', 'application/pdf', '1024');
        $file2 = $this->makeFile('folder-1', 'Photos', 'application/vnd.google-apps.folder', null);

        $fileList = new FileList();
        $fileList->setFiles([$file1, $file2]);

        $this->filesResource
            ->shouldReceive('listFiles')
            ->once()
            ->andReturn($fileList);

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(CloudFile::class, $results[0]);
        $this->assertSame('document.pdf', $results[0]->name);
        $this->assertFalse($results[0]->isFolder);
        $this->assertSame(1024, $results[0]->size);
        $this->assertSame('Photos', $results[1]->name);
        $this->assertTrue($results[1]->isFolder);
    }

    public function test_list_folder_returns_empty_when_no_files(): void
    {
        $fileList = new FileList();
        $fileList->setFiles([]);

        $this->filesResource
            ->shouldReceive('listFiles')
            ->once()
            ->andReturn($fileList);

        $results = $this->adapter->listFolder('some-folder-id');

        $this->assertSame([], $results);
    }

    public function test_create_folder_returns_cloud_file(): void
    {
        $folderFile = $this->makeFile('new-folder-id', 'MyFolder', 'application/vnd.google-apps.folder', null);

        $this->filesResource
            ->shouldReceive('create')
            ->once()
            ->with(Mockery::type(DriveFile::class), Mockery::type('array'))
            ->andReturn($folderFile);

        $result = $this->adapter->createFolder('root', 'MyFolder');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('new-folder-id', $result->id);
        $this->assertSame('MyFolder', $result->name);
        $this->assertTrue($result->isFolder);
    }

    public function test_put_file_returns_cloud_file(): void
    {
        $uploaded = $this->makeFile('uploaded-id', 'report.pdf', 'application/pdf', '2048');

        $this->filesResource
            ->shouldReceive('create')
            ->once()
            ->andReturn($uploaded);

        $result = $this->adapter->putFile('folder-id', 'report.pdf', 'binary content', 'application/pdf');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('uploaded-id', $result->id);
        $this->assertSame('report.pdf', $result->name);
        $this->assertSame(2048, $result->size);
    }

    public function test_delete_file_calls_drive_delete(): void
    {
        $this->filesResource
            ->shouldReceive('delete')
            ->once()
            ->with('file-to-delete');

        $this->adapter->deleteFile('file-to-delete');

        $this->assertTrue(true); // no exception = pass
    }

    public function test_delete_file_swallows_404(): void
    {
        $exception = new \Google\Service\Exception('Not found', 404);

        $this->filesResource
            ->shouldReceive('delete')
            ->once()
            ->andThrow($exception);

        // Should not throw
        $this->adapter->deleteFile('missing-file');

        $this->assertTrue(true);
    }

    public function test_get_metadata_returns_cloud_file(): void
    {
        $file = $this->makeFile('meta-id', 'photo.jpg', 'image/jpeg', '512000');
        $file->setWebViewLink('https://drive.google.com/file/d/meta-id/view');

        $this->filesResource
            ->shouldReceive('get')
            ->once()
            ->with('meta-id', Mockery::type('array'))
            ->andReturn($file);

        $result = $this->adapter->getMetadata('meta-id');

        $this->assertSame('photo.jpg', $result->name);
        $this->assertSame('image/jpeg', $result->mimeType);
        $this->assertSame(512000, $result->size);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeAdapter(): GoogleDriveAdapter
    {
        return new GoogleDriveAdapter(
            accessToken:  'fake-access-token',
            refreshToken: 'fake-refresh-token',
            clientId:     null,
            clientSecret: null,
        );
    }

    private function injectDriveService(GoogleDriveAdapter $adapter, $service): void
    {
        $ref = new \ReflectionClass($adapter);
        $prop = $ref->getProperty('service');
        $prop->setAccessible(true);
        $prop->setValue($adapter, $service);
    }

    private function makeFile(string $id, string $name, string $mimeType, ?string $size): DriveFile
    {
        $file = new DriveFile();
        $file->setId($id);
        $file->setName($name);
        $file->setMimeType($mimeType);
        if ($size !== null) {
            $file->setSize($size);
        }

        return $file;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
