<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\Adapters\DropboxAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Mockery;
use Mockery\MockInterface;
use Spatie\Dropbox\Client;

class DropboxAdapterTest extends PackageTestCase
{
    private MockInterface $client;
    private DropboxAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->client  = Mockery::mock(Client::class);
        $this->adapter = new DropboxAdapter('fake-token');
        $this->adapter->setClient($this->client);
    }

    public function test_list_folder_root_returns_items(): void
    {
        $this->client->allows('listFolder')
            ->with('')
            ->andReturn([
                'entries' => [
                    ['.tag' => 'folder', 'name' => 'Photos', 'path_display' => '/Photos'],
                    ['.tag' => 'file', 'name' => 'readme.txt', 'path_display' => '/readme.txt', 'size' => 42, 'server_modified' => '2024-01-01T00:00:00Z'],
                ],
                'has_more' => false,
            ]);

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);

        $folder = $results[0];
        $this->assertSame('Photos', $folder->name);
        $this->assertTrue($folder->isFolder);
        $this->assertSame('Photos', $folder->id);

        $file = $results[1];
        $this->assertSame('readme.txt', $file->name);
        $this->assertFalse($file->isFolder);
        $this->assertSame(42, $file->size);
    }

    public function test_list_folder_paginates_when_has_more(): void
    {
        $this->client->allows('listFolder')
            ->with('/docs')
            ->andReturn([
                'entries'  => [
                    ['.tag' => 'file', 'name' => 'a.txt', 'path_display' => '/docs/a.txt', 'size' => 1],
                ],
                'has_more' => true,
                'cursor'   => 'cursor-abc',
            ]);

        $this->client->allows('listFolderContinue')
            ->with('cursor-abc')
            ->andReturn([
                'entries' => [
                    ['.tag' => 'file', 'name' => 'b.txt', 'path_display' => '/docs/b.txt', 'size' => 2],
                ],
                'has_more' => false,
            ]);

        $results = $this->adapter->listFolder('docs');

        $this->assertCount(2, $results);
        $this->assertSame('a.txt', $results[0]->name);
        $this->assertSame('b.txt', $results[1]->name);
    }

    public function test_get_file_binary_returns_content(): void
    {
        $stream = $this->makeStream('hello world');

        $this->client->allows('download')
            ->with('/report.pdf')
            ->andReturn([['name' => 'report.pdf', 'size' => 11], $stream]);

        $binary = $this->adapter->getFileBinary('report.pdf');

        $this->assertSame('hello world', $binary);
    }

    public function test_put_file_in_root(): void
    {
        $this->client->allows('upload')
            ->with('/notes.txt', 'content here', 'overwrite')
            ->andReturn([
                '.tag' => 'file', 'name' => 'notes.txt',
                'path_display' => '/notes.txt', 'size' => 12,
            ]);

        $result = $this->adapter->putFile('root', 'notes.txt', 'content here', 'text/plain');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('notes.txt', $result->name);
        $this->assertFalse($result->isFolder);
    }

    public function test_put_file_in_subfolder(): void
    {
        $this->client->allows('upload')
            ->with('/docs/report.pdf', 'pdf bytes', 'overwrite')
            ->andReturn([
                '.tag' => 'file', 'name' => 'report.pdf',
                'path_display' => '/docs/report.pdf', 'size' => 9,
            ]);

        $result = $this->adapter->putFile('docs', 'report.pdf', 'pdf bytes', 'application/pdf');

        $this->assertSame('report.pdf', $result->name);
        $this->assertSame('docs', $result->parentId);
    }

    public function test_delete_file(): void
    {
        $this->client->allows('delete')
            ->with('/old.txt')
            ->andReturn(['.tag' => 'file', 'name' => 'old.txt', 'path_display' => '/old.txt']);

        $this->adapter->deleteFile('old.txt');

        $this->addToAssertionCount(
            Mockery::getContainer()->mockery_getExpectationCount()
        );
        $this->assertTrue(true);
    }

    public function test_create_folder(): void
    {
        $this->client->allows('createFolder')
            ->with('/NewFolder')
            ->andReturn([
                'metadata' => ['.tag' => 'folder', 'name' => 'NewFolder', 'path_display' => '/NewFolder'],
            ]);

        $result = $this->adapter->createFolder('root', 'NewFolder');

        $this->assertSame('NewFolder', $result->name);
        $this->assertTrue($result->isFolder);
    }

    public function test_get_metadata(): void
    {
        $this->client->allows('getMetadata')
            ->with('/doc.txt')
            ->andReturn(['.tag' => 'file', 'name' => 'doc.txt', 'path_display' => '/doc.txt', 'size' => 100]);

        $result = $this->adapter->getMetadata('doc.txt');

        $this->assertSame('doc.txt', $result->name);
        $this->assertSame(100, $result->size);
        $this->assertFalse($result->isFolder);
    }

    public function test_copy_file_same_drive(): void
    {
        $this->client->allows('copy')
            ->with('/source.txt', '/archive/source.txt')
            ->andReturn([
                'metadata' => ['.tag' => 'file', 'name' => 'source.txt', 'path_display' => '/archive/source.txt', 'size' => 5],
            ]);

        $result = $this->adapter->copyFileSameDrive('source.txt', 'archive', 'source.txt');

        $this->assertSame('source.txt', $result->name);
        $this->assertSame('archive', $result->parentId);
    }

    public function test_move_file_same_drive(): void
    {
        $this->client->allows('move')
            ->with('/source.txt', '/archive/source.txt')
            ->andReturn([
                'metadata' => ['.tag' => 'file', 'name' => 'source.txt', 'path_display' => '/archive/source.txt', 'size' => 5],
            ]);

        $result = $this->adapter->moveFileSameDrive('source.txt', 'archive', 'source.txt');

        $this->assertSame('source.txt', $result->name);
    }

    public function test_refresh_token_returns_empty(): void
    {
        $this->assertSame([], $this->adapter->refreshToken());
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeStream(string $content): object
    {
        return new class($content) {
            public function __construct(private string $content) {}
            public function __toString(): string { return $this->content; }
        };
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
