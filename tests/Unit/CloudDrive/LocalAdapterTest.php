<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\Adapters\LocalAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;

class LocalAdapterTest extends PackageTestCase
{
    private LocalAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local-test');

        $this->adapter = new LocalAdapter('local-test');
        $this->adapter->setDisk($this->fakeDisk());
    }

    public function test_list_folder_root_returns_files_and_directories(): void
    {
        $this->fakeDisk()->put('note.txt', 'hello');
        $this->fakeDisk()->makeDirectory('docs');

        $results = $this->adapter->listFolder('root');

        $names = array_map(fn (CloudFile $f) => $f->name, $results);

        $this->assertContains('note.txt', $names);
        $this->assertContains('docs', $names);
    }

    public function test_list_folder_subdirectory_returns_children(): void
    {
        $this->fakeDisk()->put('docs/report.pdf', 'pdf content');

        $results = $this->adapter->listFolder('docs');

        $this->assertCount(1, $results);
        $this->assertSame('report.pdf', $results[0]->name);
        $this->assertFalse($results[0]->isFolder);
    }

    public function test_listed_directories_are_marked_as_folders(): void
    {
        $this->fakeDisk()->makeDirectory('images');
        $this->fakeDisk()->put('images/photo.jpg', 'jpg');

        $results = $this->adapter->listFolder('root');

        $folders = array_filter($results, fn (CloudFile $f) => $f->isFolder);
        $this->assertCount(1, $folders);
        $this->assertSame('images', array_values($folders)[0]->name);
    }

    public function test_get_file_binary_returns_content(): void
    {
        $this->fakeDisk()->put('hello.txt', 'binary data');

        $content = $this->adapter->getFileBinary('hello.txt');

        $this->assertSame('binary data', $content);
    }

    public function test_get_file_binary_throws_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found/');

        $this->adapter->getFileBinary('nonexistent.txt');
    }

    public function test_put_file_root_stores_and_returns_cloud_file(): void
    {
        $result = $this->adapter->putFile('root', 'upload.txt', 'file content', 'text/plain');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('upload.txt', $result->name);
        $this->assertFalse($result->isFolder);
        $this->assertSame(12, $result->size);
        $this->assertTrue($this->fakeDisk()->exists('upload.txt'));
    }

    public function test_put_file_in_subfolder(): void
    {
        $result = $this->adapter->putFile('docs', 'report.pdf', 'pdf bytes', 'application/pdf');

        $this->assertSame('report.pdf', $result->name);
        $this->assertSame('docs', $result->parentId);
        $this->assertTrue($this->fakeDisk()->exists('docs/report.pdf'));
    }

    public function test_delete_file_removes_it(): void
    {
        $this->fakeDisk()->put('to-delete.txt', 'data');

        $this->adapter->deleteFile('to-delete.txt');

        $this->assertFalse($this->fakeDisk()->exists('to-delete.txt'));
    }

    public function test_delete_directory_removes_it(): void
    {
        $this->fakeDisk()->makeDirectory('old-dir');
        $this->fakeDisk()->put('old-dir/file.txt', 'x');

        $this->adapter->deleteFile('old-dir');

        $this->assertFalse($this->fakeDisk()->directoryExists('old-dir'));
    }

    public function test_create_folder_makes_directory(): void
    {
        $result = $this->adapter->createFolder('root', 'NewFolder');

        $this->assertSame('NewFolder', $result->name);
        $this->assertTrue($result->isFolder);
        $this->assertTrue($this->fakeDisk()->directoryExists('NewFolder'));
    }

    public function test_create_nested_folder(): void
    {
        $this->fakeDisk()->makeDirectory('parent');

        $result = $this->adapter->createFolder('parent', 'child');

        $this->assertSame('child', $result->name);
        $this->assertTrue($this->fakeDisk()->directoryExists('parent/child'));
    }

    public function test_get_metadata_for_file(): void
    {
        $this->fakeDisk()->put('meta.txt', 'some data');

        $result = $this->adapter->getMetadata('meta.txt');

        $this->assertSame('meta.txt', $result->name);
        $this->assertFalse($result->isFolder);
        $this->assertSame(9, $result->size);
    }

    public function test_get_metadata_for_directory(): void
    {
        $this->fakeDisk()->makeDirectory('my-dir');

        $result = $this->adapter->getMetadata('my-dir');

        $this->assertSame('my-dir', $result->name);
        $this->assertTrue($result->isFolder);
    }

    public function test_get_metadata_throws_for_missing_file(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->adapter->getMetadata('ghost.txt');
    }

    public function test_copy_file_same_drive(): void
    {
        $this->fakeDisk()->put('original.txt', 'content');

        $result = $this->adapter->copyFileSameDrive('original.txt', 'backup', 'original.txt');

        $this->assertSame('original.txt', $result->name);
        $this->assertTrue($this->fakeDisk()->exists('original.txt'));
        $this->assertTrue($this->fakeDisk()->exists('backup/original.txt'));
    }

    public function test_move_file_same_drive(): void
    {
        $this->fakeDisk()->put('source.txt', 'data');

        $result = $this->adapter->moveFileSameDrive('source.txt', 'archive', 'source.txt');

        $this->assertSame('source.txt', $result->name);
        $this->assertFalse($this->fakeDisk()->exists('source.txt'));
        $this->assertTrue($this->fakeDisk()->exists('archive/source.txt'));
    }

    public function test_refresh_token_returns_empty_array(): void
    {
        $this->assertSame([], $this->adapter->refreshToken());
    }

    public function test_disk_name_accessor(): void
    {
        $adapter = new LocalAdapter('public');
        $this->assertSame('public', $adapter->diskName());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function fakeDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('local-test');
    }
}
