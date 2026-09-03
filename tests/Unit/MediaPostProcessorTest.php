<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaPostProcessor;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;
use Mockery;
use Mockery\MockInterface;

class MediaPostProcessorTest extends PackageTestCase
{
    private MockInterface $files;
    private MediaPostProcessor $processor;
    private Workspace $workspace;
    private Folder $folder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->files     = Mockery::mock(FileSystemContract::class);
        $this->processor = new MediaPostProcessor($this->files, new StorageOrphanService($this->files));

        $this->workspace = Workspace::create(['name' => 'Corp', 'uuid' => Str::uuid()]);
        $this->folder = Folder::create([
            'workspace_id' => $this->workspace->id,
            'name'      => 'Root',
            'slug'      => 'root',
            'path'      => '/',
            'uuid'      => Str::uuid(),
        ]);
    }

    public function test_process_non_image_returns_media_only(): void
    {
        $media = $this->makeMedia('application/pdf', 'docs/report.pdf');

        $result = $this->processor->process($media, $this->makeOptions());

        $this->assertArrayHasKey('media', $result);
        $this->assertArrayNotHasKey('thumbnail', $result);
    }

    public function test_process_svg_skips_thumbnail(): void
    {
        $media = $this->makeMedia('image/svg+xml', 'images/logo.svg');

        $result = $this->processor->process($media, $this->makeOptions());

        $this->assertArrayNotHasKey('thumbnail', $result);
    }

    public function test_process_image_generates_thumbnail(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $png = $this->make1x1Png();
        $media = $this->makeMedia('image/png', 'images/photo.png');

        $this->files
            ->shouldReceive('get')
            ->once()
            ->andReturn($png);

        $this->files
            ->shouldReceive('put')
            ->once()
            ->andReturn(true);

        $result = $this->processor->process($media, $this->makeOptions());

        $this->assertArrayHasKey('thumbnail', $result);
        $this->assertStringContainsString('.thumbnails', $result['thumbnail']);
        $this->assertStringEndsWith('.jpg', $result['thumbnail']);
    }

    public function test_process_image_updates_thumbnail_path_on_media(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $media = $this->makeMedia('image/jpeg', 'photos/beach.jpg');

        $this->files->shouldReceive('get')->andReturn($this->make1x1Png());
        $this->files->shouldReceive('put')->andReturn(true);

        $this->processor->process($media, $this->makeOptions());

        $media->refresh();
        $this->assertNotNull($media->thumbnail_path);
        $this->assertStringContainsString('.thumbnails', $media->thumbnail_path);
    }

    public function test_process_returns_null_thumbnail_on_fs_error(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $media = $this->makeMedia('image/jpeg', 'photos/pic.jpg');

        $this->files->shouldReceive('get')->andReturn($this->make1x1Png());
        $this->files->shouldReceive('put')->andThrow(new \RuntimeException('FS error'));
        $this->files->shouldReceive('exists')->once()->andReturnFalse();

        $result = $this->processor->process($media, $this->makeOptions());

        $this->assertArrayNotHasKey('thumbnail', $result);
    }

    public function test_process_skips_when_source_read_fails(): void
    {
        $media = $this->makeMedia('image/jpeg', 'photos/pic.jpg');

        $this->files->shouldReceive('get')->andThrow(new \RuntimeException('File not found'));

        $result = $this->processor->process($media, $this->makeOptions());

        $this->assertArrayNotHasKey('thumbnail', $result);
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function makeMedia(string $mimeType, string $path): Media
    {
        return Media::create([
            'workspace_id'  => $this->workspace->id,
            'folder_id'  => $this->folder->id,
            'uuid'       => Str::uuid(),
            'disk'       => Disk::PUBLIC,
            'path'       => $path,
            'mime_type'  => $mimeType,
        ]);
    }

    private function makeOptions(): MediaUploadOptions
    {
        return new MediaUploadOptions();
    }

    private function make1x1Png(): string
    {
        $img = imagecreatetruecolor(1, 1);
        ob_start();
        imagepng($img);
        $binary = ob_get_clean();
        imagedestroy($img);

        return $binary;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
