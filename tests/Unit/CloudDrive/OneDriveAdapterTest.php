<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\CloudFile;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\OneDriveAdapter;
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
            clientId: 'client-id',
            clientSecret: 'client-secret',
        );
    }

    public function test_list_folder_root_returns_cloud_files(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root/children*' => Http::response([
                'value' => [
                    $this->item('folder-1', 'Documents', folder: true),
                    $this->item('file-1', 'report.pdf', size: 4096, mimeType: 'application/pdf'),
                ],
            ]),
        ]);

        $results = $this->adapter->listFolder('root');

        $this->assertCount(2, $results);
        $this->assertInstanceOf(CloudFile::class, $results[0]);
        $this->assertTrue($results[0]->isFolder);
        $this->assertSame('Documents', $results[0]->name);
        $this->assertFalse($results[1]->isFolder);
        $this->assertSame(4096, $results[1]->size);
    }

    public function test_list_folder_follows_graph_pagination(): void
    {
        Http::fake(static function (Request $request) {
            if (str_contains($request->url(), 'page=2')) {
                return Http::response([
                    'value' => [[
                        'id' => 'file-2',
                        'name' => 'two.txt',
                        'size' => 2,
                        'file' => ['mimeType' => 'text/plain'],
                    ]],
                ]);
            }

            return Http::response([
                'value' => [[
                    'id' => 'file-1',
                    'name' => 'one.txt',
                    'size' => 1,
                    'file' => ['mimeType' => 'text/plain'],
                ]],
                '@odata.nextLink' => 'https://graph.microsoft.com/v1.0/me/drive/root/children?page=2',
            ]);
        });

        $results = $this->adapter->listFolder();

        $this->assertSame(['file-1', 'file-2'], array_map(fn (CloudFile $file) => $file->id, $results));
    }

    public function test_create_folder_returns_cloud_file(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root/children' => Http::response(
                $this->item('new-folder', 'Projects', folder: true),
                201,
            ),
        ]);

        $result = $this->adapter->createFolder('root', 'Projects');

        $this->assertSame('new-folder', $result->id);
        $this->assertTrue($result->isFolder);
    }

    public function test_put_file_returns_cloud_file(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/root:/photo.jpg:/content*' => Http::response(
                $this->item('up-file', 'photo.jpg', size: 8192, mimeType: 'image/jpeg'),
                201,
            ),
        ]);

        $result = $this->adapter->putFile('root', 'photo.jpg', 'binary', 'image/jpeg');

        $this->assertSame('up-file', $result->id);
        $this->assertSame('photo.jpg', $result->name);
        $this->assertSame(8192, $result->size);
    }

    public function test_delete_file_treats_missing_item_as_idempotent_success(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/items/file-id' => Http::response([], 404),
        ]);

        $this->adapter->deleteFile('file-id');

        Http::assertSentCount(1);
    }

    public function test_get_metadata_returns_cloud_file(): void
    {
        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/items/meta-id*' => Http::response(
                $this->item(
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

    public function test_get_file_binary_downloads_via_pre_authenticated_url(): void
    {
        $downloadUrl = 'https://download.example.com/file';

        Http::fake([
            'https://graph.microsoft.com/v1.0/me/drive/items/file-bin*' => Http::response([
                'id' => 'file-bin',
                '@microsoft.graph.downloadUrl' => $downloadUrl,
            ]),
            $downloadUrl => Http::response('file content', 200),
        ]);

        $this->assertSame('file content', $this->adapter->getFileBinary('file-bin'));
    }

    public function test_refresh_token_updates_credentials_without_graph_sdk(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/common/oauth2/v2.0/token' => Http::response([
                'access_token' => 'new-access-token',
                'refresh_token' => 'new-refresh-token',
                'expires_in' => 1800,
            ]),
        ]);

        $result = $this->adapter->refreshToken();

        $this->assertSame('new-access-token', $result['access_token']);
        $this->assertSame('new-refresh-token', $result['refresh_token']);
    }

    /** @return array<string, mixed> */
    private function item(
        string $id,
        string $name,
        bool $folder = false,
        ?int $size = null,
        ?string $mimeType = null,
    ): array {
        $item = [
            'id' => $id,
            'name' => $name,
            'size' => $size,
            'webUrl' => "https://onedrive.example.com/{$id}",
            'lastModifiedDateTime' => '2024-01-01T12:00:00Z',
            'parentReference' => ['id' => 'parent-id'],
        ];

        if ($folder) {
            $item['folder'] = new \stdClass;
        } else {
            $item['file'] = ['mimeType' => $mimeType];
        }

        return $item;
    }
}
