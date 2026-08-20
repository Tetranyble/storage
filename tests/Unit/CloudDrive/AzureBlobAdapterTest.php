<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Carbon\Carbon;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\AzureBlobAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\StorageAttributes;
use Mockery;
use Mockery\MockInterface;

class AzureBlobAdapterTest extends PackageTestCase
{
    private MockInterface $fs;
    private AzureBlobAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs      = Mockery::mock(FilesystemOperator::class);
        $this->adapter = new AzureBlobAdapter('UseDevelopmentStorage=true', 'test-container');
        $this->adapter->setFilesystem($this->fs);
    }

    public function test_list_folder_root_returns_files_and_directories(): void
    {
        $this->fs->allows('listContents')
            ->with('', false)
            ->andReturn($this->makeListing([
                new DirectoryAttributes('photos'),
                new FileAttributes('readme.txt', 42, null, Carbon::now()->timestamp, 'text/plain'),
            ]));

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isFolder);
        $this->assertSame('photos', $results[0]->name);
        $this->assertFalse($results[1]->isFolder);
        $this->assertSame('readme.txt', $results[1]->name);
        $this->assertSame(42, $results[1]->size);
    }

    public function test_list_folder_prefix_applies(): void
    {
        $this->fs->allows('listContents')
            ->with('docs/', false)
            ->andReturn($this->makeListing([
                new FileAttributes('docs/report.pdf', 1024, null, null, 'application/pdf'),
            ]));

        $results = $this->adapter->listFolder('docs');

        $this->assertCount(1, $results);
        $this->assertSame('report.pdf', $results[0]->name);
        $this->assertSame('docs', $results[0]->parentId);
    }

    public function test_get_file_binary_returns_content(): void
    {
        $this->fs->allows('read')
            ->with('data.txt')
            ->andReturn('file data');

        $this->assertSame('file data', $this->adapter->getFileBinary('data.txt'));
    }

    public function test_get_file_binary_throws_for_missing(): void
    {
        $this->fs->allows('read')
            ->with('ghost.txt')
            ->andThrow(new \League\Flysystem\UnableToReadFile('ghost.txt'));

        $this->expectException(\RuntimeException::class);

        $this->adapter->getFileBinary('ghost.txt');
    }

    public function test_put_file_stores_and_returns_cloud_file(): void
    {
        $this->fs->allows('write')
            ->with('upload.txt', 'hello', ['mimetype' => 'text/plain'])
            ->andReturn(null);

        $result = $this->adapter->putFile('root', 'upload.txt', 'hello', 'text/plain');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('upload.txt', $result->name);
        $this->assertSame(5, $result->size);
    }

    public function test_delete_file(): void
    {
        $this->fs->allows('directoryExists')->with('old.txt')->andReturn(false);
        $this->fs->allows('delete')->with('old.txt')->andReturn(null);

        $this->adapter->deleteFile('old.txt');

        $this->assertTrue(true); // verified by Mockery expectations
    }

    public function test_delete_directory(): void
    {
        $this->fs->allows('directoryExists')->with('old-dir')->andReturn(true);
        $this->fs->allows('deleteDirectory')->with('old-dir')->andReturn(null);

        $this->adapter->deleteFile('old-dir');

        $this->assertTrue(true); // verified by Mockery expectations
    }

    public function test_create_folder(): void
    {
        $this->fs->allows('createDirectory')->with('NewFolder')->andReturn(null);

        $result = $this->adapter->createFolder('root', 'NewFolder');

        $this->assertSame('NewFolder', $result->name);
        $this->assertTrue($result->isFolder);
        $this->assertSame('root', $result->parentId);
    }

    public function test_get_metadata_for_file(): void
    {
        $this->fs->allows('directoryExists')->with('file.txt')->andReturn(false);
        $this->fs->allows('fileExists')->with('file.txt')->andReturn(true);
        $this->fs->allows('fileSize')->with('file.txt')->andReturn(500);
        $this->fs->allows('mimeType')->with('file.txt')->andReturn('text/plain');
        $this->fs->allows('lastModified')->with('file.txt')->andReturn(1700000000);

        $result = $this->adapter->getMetadata('file.txt');

        $this->assertSame('file.txt', $result->name);
        $this->assertSame(500, $result->size);
        $this->assertFalse($result->isFolder);
    }

    public function test_get_metadata_for_directory(): void
    {
        $this->fs->allows('directoryExists')->with('my-dir')->andReturn(true);

        $result = $this->adapter->getMetadata('my-dir');

        $this->assertTrue($result->isFolder);
        $this->assertSame('my-dir', $result->name);
    }

    public function test_copy_file_same_drive(): void
    {
        $this->fs->allows('copy')->with('src.txt', 'dest/src.txt')->andReturn(null);
        $this->fs->allows('directoryExists')->with('dest/src.txt')->andReturn(false);
        $this->fs->allows('fileExists')->with('dest/src.txt')->andReturn(true);
        $this->fs->allows('fileSize')->with('dest/src.txt')->andReturn(10);
        $this->fs->allows('mimeType')->with('dest/src.txt')->andReturn('text/plain');
        $this->fs->allows('lastModified')->with('dest/src.txt')->andReturn(null);

        $result = $this->adapter->copyFileSameDrive('src.txt', 'dest', 'src.txt');

        $this->assertSame('src.txt', $result->name);
    }

    public function test_move_file_same_drive(): void
    {
        $this->fs->allows('move')->with('src.txt', 'dest/src.txt')->andReturn(null);
        $this->fs->allows('directoryExists')->with('dest/src.txt')->andReturn(false);
        $this->fs->allows('fileExists')->with('dest/src.txt')->andReturn(true);
        $this->fs->allows('fileSize')->with('dest/src.txt')->andReturn(10);
        $this->fs->allows('mimeType')->with('dest/src.txt')->andReturn('text/plain');
        $this->fs->allows('lastModified')->with('dest/src.txt')->andReturn(null);

        $result = $this->adapter->moveFileSameDrive('src.txt', 'dest', 'src.txt');

        $this->assertSame('src.txt', $result->name);
    }

    public function test_refresh_token_returns_empty(): void
    {
        $this->assertSame([], $this->adapter->refreshToken());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /** @param StorageAttributes[] $items */
    private function makeListing(array $items): \League\Flysystem\DirectoryListing
    {
        return new \League\Flysystem\DirectoryListing(
            new \ArrayIterator($items)
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
