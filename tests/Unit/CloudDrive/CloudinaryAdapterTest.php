<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\Adapters\CloudinaryAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Tests\PackageTestCase;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Upload\UploadApi;
use Illuminate\Support\Facades\Http;
use Mockery;
use Mockery\MockInterface;

class CloudinaryAdapterTest extends PackageTestCase
{
    private MockInterface $uploadApi;
    private MockInterface $adminApi;
    private CloudinaryAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uploadApi = Mockery::mock(UploadApi::class);
        $this->adminApi  = Mockery::mock(AdminApi::class);

        $this->adapter = new CloudinaryAdapter('my-cloud', 'key', 'secret');
        $this->adapter->setUploadApi($this->uploadApi);
        $this->adapter->setAdminApi($this->adminApi);
    }

    public function test_list_folder_root_returns_folders_and_assets(): void
    {
        $this->adminApi->allows('rootFolders')
            ->andReturn(['folders' => [['name' => 'banners', 'path' => 'banners']]]);

        $this->adminApi->allows('assets')
            ->andReturn([
                'resources' => [
                    ['public_id' => 'logo', 'bytes' => 2048, 'resource_type' => 'image', 'format' => 'png', 'secure_url' => 'https://res.cloudinary.com/logo.png', 'created_at' => '2024-01-01T00:00:00Z'],
                ],
            ]);

        $results = $this->adapter->listFolder('root');

        $names = array_map(fn (CloudFile $f) => $f->name, $results);
        $this->assertContains('banners', $names);
        $this->assertContains('logo', $names);

        $folder = collect($results)->firstWhere('isFolder', true);
        $this->assertNotNull($folder);
        $this->assertSame('banners', $folder->id);
    }

    public function test_list_folder_sub_returns_children(): void
    {
        $this->adminApi->allows('subFolders')
            ->with('marketing/')
            ->andReturn(['folders' => []]);

        $this->adminApi->allows('assets')
            ->andReturn([
                'resources' => [
                    ['public_id' => 'marketing/banner', 'bytes' => 100, 'resource_type' => 'image', 'format' => 'jpg', 'secure_url' => 'https://cdn/banner.jpg', 'created_at' => '2024-01-01T00:00:00Z'],
                ],
            ]);

        $results = $this->adapter->listFolder('marketing');

        $this->assertCount(1, $results);
        $this->assertSame('banner', $results[0]->name);
        $this->assertSame('marketing/banner', $results[0]->id);
    }

    public function test_get_file_binary_downloads_via_url(): void
    {
        $this->adminApi->allows('asset')
            ->with('marketing/logo')
            ->andReturn(['secure_url' => 'https://res.cloudinary.com/logo.png', 'public_id' => 'marketing/logo']);

        Http::fake(['https://res.cloudinary.com/logo.png' => Http::response('png-bytes', 200)]);

        $binary = $this->adapter->getFileBinary('marketing/logo');

        $this->assertSame('png-bytes', $binary);
    }

    public function test_get_file_binary_throws_on_missing_url(): void
    {
        $this->adminApi->allows('asset')
            ->with('ghost')
            ->andReturn(['public_id' => 'ghost']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/download URL/');

        $this->adapter->getFileBinary('ghost');
    }

    public function test_put_file_uploads_and_returns_cloud_file(): void
    {
        $this->uploadApi->allows('upload')
            ->andReturn([
                'public_id'     => 'docs/report',
                'bytes'         => 9,
                'resource_type' => 'raw',
                'format'        => 'pdf',
                'secure_url'    => 'https://cdn/report.pdf',
                'created_at'    => '2024-01-01T00:00:00Z',
            ]);

        $result = $this->adapter->putFile('docs', 'report.pdf', 'pdf bytes', 'application/pdf');

        $this->assertInstanceOf(CloudFile::class, $result);
        $this->assertSame('report', $result->name);
        $this->assertSame('docs/report', $result->id);
    }

    public function test_create_folder(): void
    {
        $this->adminApi->allows('createFolder')
            ->with('marketing/social')
            ->andReturn(['success' => true]);

        $result = $this->adapter->createFolder('marketing', 'social');

        $this->assertSame('social', $result->name);
        $this->assertTrue($result->isFolder);
        $this->assertSame('marketing/social', $result->id);
    }

    public function test_get_metadata(): void
    {
        $this->adminApi->allows('asset')
            ->with('product/shot')
            ->andReturn([
                'public_id'     => 'product/shot',
                'bytes'         => 5120,
                'resource_type' => 'image',
                'format'        => 'jpg',
                'secure_url'    => 'https://cdn/shot.jpg',
                'created_at'    => '2024-06-01T00:00:00Z',
            ]);

        $result = $this->adapter->getMetadata('product/shot');

        $this->assertSame('shot', $result->name);
        $this->assertSame(5120, $result->size);
        $this->assertSame('image/jpg', $result->mimeType);
        $this->assertSame('https://cdn/shot.jpg', $result->webViewLink);
    }

    public function test_refresh_token_returns_empty(): void
    {
        $this->assertSame([], $this->adapter->refreshToken());
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
