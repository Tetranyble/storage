<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\OneDriveAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;

class OneDriveAdapterTest extends PackageTestCase
{
    private OneDriveAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = new OneDriveAdapter(
            accessToken: 'fake-access-token',
            refreshToken: 'fake-refresh-token',
            clientId: null,
            clientSecret: null,
        );
    }

    public function test_list_folder_root_returns_cloud_files(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children*' => Http::response([
                'value' => [
                    $this->makeDriveItem('folder-1', 'Documents', isFolder: true),
                    $this->makeDriveItem('file-1', 'report.pdf', size: 4096, mimeType: 'application/pdf'),
                ],
            ]),
        ]);

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(CloudFile::class, $results[0]);
        $this->assertTrue($results[0]->isFolder);
        $this->assertSame('Documents', $results[0]->name);
        $this->assertFalse($results[1]->isFolder);
        $this->assertSame('report.pdf', $results[1]->name);
        $this->assertSame(4096, $results[1]->size);
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('Authorization', 'Bearer fake-access-token'));
    }

    public function test_create_folder_returns_cloud_file(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root/children' => Http::response(
                $this->makeDriveItem('new-folder', 'Projects', isFolder: true),
                201,
            ),
        ]);

        $result = $this->adapter->createFolder('root', 'Projects');

        $this->assertSame('new-folder', $result->id);
        $this->assertTrue($result->isFolder);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request['name'] === 'Projects'
            && $request['@microsoft.graph.conflictBehavior'] === 'rename');
    }

    public function test_put_file_returns_cloud_file(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/root:/photo.jpg:/content*' => Http::response(
                $this->makeDriveItem('up-file', 'photo.jpg', size: 8192, mimeType: 'image/jpeg'),
                201,
            ),
        ]);

        $result = $this->adapter->putFile('root', 'photo.jpg', 'binary', 'image/jpeg');

        $this->assertSame('up-file', $result->id);
        $this->assertSame('photo.jpg', $result->name);
        $this->assertSame(8192, $result->size);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && $request->body() === 'binary'
            && $request->hasHeader('Content-Type', 'image/jpeg'));
    }

    public function test_delete_file_sends_delete_request(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/items/file-id' => Http::response(null, 204),
        ]);

        $this->adapter->deleteFile('file-id');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE');
    }

    public function test_get_metadata_returns_cloud_file(): void
    {
        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/items/meta-id*' => Http::response(
                $this->makeDriveItem(
                    'meta-id',
                    'slides.pptx',
                    size: 2048000,
                    mimeType: 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
                ),
            ),
        ]);

        $result = $this->adapter->getMetadata('meta-id');

        $this->assertSame('meta-id', $result->id);
        $this->assertSame('slides.pptx', $result->name);
    }

    public function test_get_file_binary_downloads_via_pre_auth_url(): void
    {
        $downloadUrl = 'https://download.example.com/file';

        Http::fake([
            'graph.microsoft.com/v1.0/me/drive/items/file-bin*' => Http::response([
                'id' => 'file-bin',
                '@microsoft.graph.downloadUrl' => $downloadUrl,
            ]),
            $downloadUrl => Http::response('file content'),
        ]);

        $binary = $this->adapter->getFileBinary('file-bin');

        $this->assertSame('file content', $binary);
        Http::assertSent(fn (Request $request): bool => $request->url() === $downloadUrl
            && ! $request->hasHeader('Authorization'));
    }

    /**
     * @return array<string, mixed>
     */
    private function makeDriveItem(
        string $id,
        string $name,
        bool $isFolder = false,
        ?int $size = null,
        ?string $mimeType = null,
    ): array {
        $item = [
            'id' => $id,
            'name' => $name,
            'size' => $size,
            'webUrl' => "https://onedrive.example.com/{$id}",
            'lastModifiedDateTime' => '2024-01-01T12:00:00Z',
            'parentReference' => null,
        ];

        if ($isFolder) {
            $item['folder'] = ['childCount' => 0];
        } else {
            $item['file'] = ['mimeType' => $mimeType];
        }

        return $item;
    }
}
