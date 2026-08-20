<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\Adapters\S3Adapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;

class S3AdapterTest extends PackageTestCase
{
    private S3Adapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        // Use a local fake disk so Storage::build() isn't called; inject via reflection
        Storage::fake('s3-test');

        $this->adapter = $this->makeAdapterWithFakeDisk();
    }

    public function test_list_folder_root_returns_items(): void
    {
        $this->fakeDisk()->put('file.txt', 'hello');
        $this->fakeDisk()->makeDirectory('subfolder');
        $this->fakeDisk()->put('subfolder/.keep', '');

        $results = $this->adapter->listFolder('root');

        $names = array_column(array_map(fn (CloudFile $f) => $f->toArray(), $results), 'name');

        $this->assertContains('file.txt', $names);
        $this->assertContains('subfolder', $names);
    }

    public function test_list_folder_subfolder_returns_children(): void
    {
        $this->fakeDisk()->put('docs/report.pdf', 'pdf content');

        $results = $this->adapter->listFolder('docs');

        $this->assertCount(1, $results);
        $this->assertSame('report.pdf', $results[0]->name);
        $this->assertFalse($results[0]->isFolder);
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

        $this->adapter->getFileBinary('nonexistent.txt');
    }

    public function test_put_file_stores_and_returns_cloud_file(): void
    {
        $result = $this->adapter->putFile('root', 'upload.txt', 'file content', 'text/plain');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('upload.txt', $result->name);
        $this->assertFalse($result->isFolder);
        $this->assertSame(12, $result->size); // strlen('file content')
        $this->assertTrue($this->fakeDisk()->exists('upload.txt'));
    }

    public function test_put_file_in_subfolder(): void
    {
        $result = $this->adapter->putFile('docs', 'report.pdf', 'pdf bytes', 'application/pdf');

        $this->assertSame('report.pdf', $result->name);
        $this->assertTrue($this->fakeDisk()->exists('docs/report.pdf'));
    }

    public function test_delete_file_removes_it(): void
    {
        $this->fakeDisk()->put('to-delete.txt', 'data');

        $this->adapter->deleteFile('to-delete.txt');

        $this->assertFalse($this->fakeDisk()->exists('to-delete.txt'));
    }

    public function test_create_folder_adds_keep_file(): void
    {
        $result = $this->adapter->createFolder('root', 'NewFolder');

        $this->assertSame('NewFolder', $result->name);
        $this->assertTrue($result->isFolder);
        $this->assertTrue($this->fakeDisk()->exists('NewFolder/.keep'));
    }

    public function test_get_metadata_for_file(): void
    {
        $this->fakeDisk()->put('meta.txt', 'some data');

        $result = $this->adapter->getMetadata('meta.txt');

        $this->assertSame('meta.txt', $result->name);
        $this->assertFalse($result->isFolder);
    }

    public function test_refresh_token_returns_empty_array(): void
    {
        $result = $this->adapter->refreshToken();

        $this->assertSame([], $result);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeAdapterWithFakeDisk(): S3Adapter
    {
        $adapter = new S3Adapter(
            bucket:   'test-bucket',
            key:      'fake-key',
            secret:   'fake-secret',
            region:   'us-east-1',
        );

        $ref  = new \ReflectionClass($adapter);
        $prop = $ref->getProperty('disk');
        $prop->setAccessible(true);
        $prop->setValue($adapter, $this->fakeDisk());

        return $adapter;
    }

    private function fakeDisk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk('s3-test');
    }
}
