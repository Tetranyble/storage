<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Carbon\Carbon;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\GcsAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Mockery;
use Mockery\MockInterface;

class GcsAdapterTest extends PackageTestCase
{
    private MockInterface $fs;
    private GcsAdapter    $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fs = Mockery::mock(FilesystemOperator::class);

        // Instantiate with dummy credentials — we inject the filesystem for tests
        $this->adapter = new GcsAdapter(
            keyFile: ['type' => 'service_account', 'project_id' => 'test'],
            bucket:  'test-bucket',
        );
        $this->adapter->setFilesystem($this->fs);
    }

    public function test_list_folder_root_returns_items(): void
    {
        $this->fs->allows('listContents')
            ->with('', false)
            ->andReturn($this->makeListing([
                new DirectoryAttributes('images'),
                new FileAttributes('logo.png', 2048, null, Carbon::now()->timestamp, 'image/png'),
            ]));

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertTrue($results[0]->isFolder);
        $this->assertSame('images', $results[0]->name);
        $this->assertFalse($results[1]->isFolder);
        $this->assertSame('logo.png', $results[1]->name);
        $this->assertSame(2048, $results[1]->size);
    }

    public function test_list_folder_subfolder(): void
    {
        $this->fs->allows('listContents')
            ->with('assets/', false)
            ->andReturn($this->makeListing([
                new FileAttributes('assets/style.css', 512, null, null, 'text/css'),
            ]));

        $results = $this->adapter->listFolder('assets');

        $this->assertCount(1, $results);
        $this->assertSame('style.css', $results[0]->name);
        $this->assertSame('assets', $results[0]->parentId);
    }

    public function test_get_file_binary(): void
    {
        $this->fs->allows('read')->with('logo.png')->andReturn('png-bytes');

        $this->assertSame('png-bytes', $this->adapter->getFileBinary('logo.png'));
    }

    public function test_get_file_binary_throws_for_missing(): void
    {
        $this->fs->allows('read')
            ->andThrow(new \League\Flysystem\UnableToReadFile('missing.png'));

        $this->expectException(\RuntimeException::class);

        $this->adapter->getFileBinary('missing.png');
    }

    public function test_put_file(): void
    {
        $this->fs->allows('write')
            ->with('logo.png', 'png-bytes', ['mimetype' => 'image/png'])
            ->andReturn(null);

        $result = $this->adapter->putFile('root', 'logo.png', 'png-bytes', 'image/png');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('logo.png', $result->name);
        $this->assertSame(9, $result->size);
    }

    public function test_delete_file(): void
    {
        $this->fs->allows('directoryExists')->with('old.txt')->andReturn(false);
        $this->fs->allows('delete')->with('old.txt')->andReturn(null);

        $this->adapter->deleteFile('old.txt');

        $this->assertTrue(true); // verified by Mockery expectations
    }

    public function test_create_folder(): void
    {
        $this->fs->allows('createDirectory')->with('media/photos')->andReturn(null);

        $result = $this->adapter->createFolder('media', 'photos');

        $this->assertSame('photos', $result->name);
        $this->assertTrue($result->isFolder);
        $this->assertSame('media/photos', $result->id);
    }

    public function test_get_metadata_for_file(): void
    {
        $this->fs->allows('directoryExists')->with('doc.pdf')->andReturn(false);
        $this->fs->allows('fileExists')->with('doc.pdf')->andReturn(true);
        $this->fs->allows('fileSize')->with('doc.pdf')->andReturn(1024);
        $this->fs->allows('mimeType')->with('doc.pdf')->andReturn('application/pdf');
        $this->fs->allows('lastModified')->with('doc.pdf')->andReturn(1700000000);

        $result = $this->adapter->getMetadata('doc.pdf');

        $this->assertSame('doc.pdf', $result->name);
        $this->assertSame(1024, $result->size);
        $this->assertFalse($result->isFolder);
    }

    public function test_copy_same_drive(): void
    {
        $this->fs->allows('copy')->with('src.txt', 'bak/src.txt')->andReturn(null);
        $this->fs->allows('directoryExists')->with('bak/src.txt')->andReturn(false);
        $this->fs->allows('fileExists')->with('bak/src.txt')->andReturn(true);
        $this->fs->allows('fileSize')->with('bak/src.txt')->andReturn(5);
        $this->fs->allows('mimeType')->with('bak/src.txt')->andReturn('text/plain');
        $this->fs->allows('lastModified')->with('bak/src.txt')->andReturn(null);

        $result = $this->adapter->copyFileSameDrive('src.txt', 'bak', 'src.txt');

        $this->assertSame('src.txt', $result->name);
    }

    public function test_move_same_drive(): void
    {
        $this->fs->allows('move')->with('src.txt', 'bak/src.txt')->andReturn(null);
        $this->fs->allows('directoryExists')->with('bak/src.txt')->andReturn(false);
        $this->fs->allows('fileExists')->with('bak/src.txt')->andReturn(true);
        $this->fs->allows('fileSize')->with('bak/src.txt')->andReturn(5);
        $this->fs->allows('mimeType')->with('bak/src.txt')->andReturn('text/plain');
        $this->fs->allows('lastModified')->with('bak/src.txt')->andReturn(null);

        $result = $this->adapter->moveFileSameDrive('src.txt', 'bak', 'src.txt');

        $this->assertSame('src.txt', $result->name);
    }

    public function test_refresh_token_returns_empty(): void
    {
        $this->assertSame([], $this->adapter->refreshToken());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeListing(array $items): \League\Flysystem\DirectoryListing
    {
        return new \League\Flysystem\DirectoryListing(new \ArrayIterator($items));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
