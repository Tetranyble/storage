<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Microsoft\Graph\Graph;
use Microsoft\Graph\Http\GraphRequest;
use Microsoft\Graph\Model\DriveItem;
use Microsoft\Graph\Model\File as GraphFile;
use Microsoft\Graph\Model\Folder as GraphFolder;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\OneDriveAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

class OneDriveAdapterTest extends PackageTestCase
{
    private MockInterface $graph;
    private OneDriveAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->graph   = Mockery::mock(Graph::class);
        $this->adapter = $this->makeAdapter();
        $this->injectGraph($this->adapter, $this->graph);
    }

    public function test_list_folder_root_returns_cloud_files(): void
    {
        $folder = $this->makeDriveItem('folder-1', 'Documents', isFolder: true);
        $file   = $this->makeDriveItem('file-1', 'report.pdf', isFolder: false, size: 4096, mimeType: 'application/pdf');

        $request = $this->mockGraphRequest([$folder, $file]);
        $this->graph->shouldReceive('createRequest')->with('GET', Mockery::type('string'))->once()->andReturn($request);

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(CloudFile::class, $results[0]);
        $this->assertTrue($results[0]->isFolder);
        $this->assertSame('Documents', $results[0]->name);
        $this->assertFalse($results[1]->isFolder);
        $this->assertSame('report.pdf', $results[1]->name);
        $this->assertSame(4096, $results[1]->size);
    }

    public function test_create_folder_returns_cloud_file(): void
    {
        $created = $this->makeDriveItem('new-folder', 'Projects', isFolder: true);
        $request = $this->mockGraphRequest($created);

        $this->graph->shouldReceive('createRequest')->with('POST', Mockery::type('string'))->once()->andReturn($request);

        $result = $this->adapter->createFolder('root', 'Projects');

        $this->assertSame('new-folder', $result->id);
        $this->assertTrue($result->isFolder);
    }

    public function test_put_file_returns_cloud_file(): void
    {
        $uploaded = $this->makeDriveItem('up-file', 'photo.jpg', isFolder: false, size: 8192, mimeType: 'image/jpeg');
        $request  = $this->mockGraphRequest($uploaded);

        $this->graph->shouldReceive('createRequest')->with('PUT', Mockery::type('string'))->once()->andReturn($request);

        $result = $this->adapter->putFile('root', 'photo.jpg', 'binary', 'image/jpeg');

        $this->assertSame('up-file', $result->id);
        $this->assertSame('photo.jpg', $result->name);
        $this->assertSame(8192, $result->size);
    }

    public function test_delete_file_sends_delete_request(): void
    {
        $request = Mockery::mock(GraphRequest::class);
        $request->shouldReceive('execute')->once()->andReturn(null);

        $this->graph->shouldReceive('createRequest')->with('DELETE', Mockery::type('string'))->once()->andReturn($request);

        $this->adapter->deleteFile('file-id');

        $this->assertTrue(true);
    }

    public function test_get_metadata_returns_cloud_file(): void
    {
        $item    = $this->makeDriveItem('meta-id', 'slides.pptx', isFolder: false, size: 2048000, mimeType: 'application/vnd.openxmlformats-officedocument.presentationml.presentation');
        $request = $this->mockGraphRequest($item);

        $this->graph->shouldReceive('createRequest')->with('GET', Mockery::type('string'))->once()->andReturn($request);

        $result = $this->adapter->getMetadata('meta-id');

        $this->assertSame('meta-id', $result->id);
        $this->assertSame('slides.pptx', $result->name);
    }

    public function test_get_file_binary_downloads_via_pre_auth_url(): void
    {
        $downloadUrl = 'https://download.example.com/file';

        // Build the item mock directly with the download URL in properties
        $item = Mockery::mock(DriveItem::class);
        $item->allows('getId')->andReturn('file-bin');
        $item->allows('getName')->andReturn('data.csv');
        $item->allows('getProperties')->andReturn(['@microsoft.graph.downloadUrl' => $downloadUrl]);

        $request = $this->mockGraphRequest($item);
        $this->graph->shouldReceive('createRequest')->with('GET', Mockery::type('string'))->once()->andReturn($request);

        Http::fake([$downloadUrl => Http::response('file content', 200)]);

        $binary = $this->adapter->getFileBinary('file-bin');

        $this->assertSame('file content', $binary);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeAdapter(): OneDriveAdapter
    {
        return new OneDriveAdapter(
            accessToken:  'fake-access-token',
            refreshToken: 'fake-refresh-token',
            clientId:     null,
            clientSecret: null,
        );
    }

    private function injectGraph(OneDriveAdapter $adapter, $graph): void
    {
        $ref  = new \ReflectionClass($adapter);
        $prop = $ref->getProperty('graph');
        $prop->setAccessible(true);
        $prop->setValue($adapter, $graph);
    }

    private function makeDriveItem(
        string  $id,
        string  $name,
        bool    $isFolder,
        ?int    $size     = null,
        ?string $mimeType = null,
    ): DriveItem|MockInterface {
        $item = Mockery::mock(DriveItem::class)->makePartial();
        $item->allows('getId')->andReturn($id);
        $item->allows('getName')->andReturn($name);
        $item->allows('getSize')->andReturn($size);
        $item->allows('getWebUrl')->andReturn("https://onedrive.example.com/{$id}");
        $item->allows('getLastModifiedDateTime')->andReturn('2024-01-01T12:00:00Z');
        $item->allows('getParentReference')->andReturn(null);
        $item->allows('getProperties')->andReturn([]);

        if ($isFolder) {
            $folderModel = new GraphFolder();
            $item->allows('getFolder')->andReturn($folderModel);
            $item->allows('getFile')->andReturn(null);
        } else {
            $fileModel = Mockery::mock(GraphFile::class)->makePartial();
            $fileModel->allows('getMimeType')->andReturn($mimeType);
            $item->allows('getFile')->andReturn($fileModel);
            $item->allows('getFolder')->andReturn(null);
        }

        return $item;
    }

    private function mockGraphRequest(mixed $returnValue): MockInterface
    {
        $request = Mockery::mock(GraphRequest::class);
        $request->shouldReceive('setReturnType')->andReturnSelf();
        $request->shouldReceive('addHeaders')->andReturnSelf();
        $request->shouldReceive('attachBody')->andReturnSelf();
        $request->shouldReceive('execute')->andReturn($returnValue);

        return $request;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
