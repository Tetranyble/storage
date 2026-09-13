<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\UnsafeMediaException;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\ExifOrientationReader;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\ImageOrientationNormalizer;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaPostProcessorTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config()->set('tetranyble-storage.derivatives.thumbnail.enabled', true);
        config()->set('tetranyble-storage.derivatives.thumbnail.formats', ['jpeg']);
        config()->set('tetranyble-storage.derivatives.thumbnail.primary_format', 'jpeg');
        config()->set('tetranyble-storage.derivatives.preview.enabled', false);
    }

    public function test_non_image_creates_no_derivatives(): void
    {
        [$workspace, $media] = $this->media('application/pdf', 'docs/report.pdf', 'pdf');

        $result = $this->processor()->process($media, new MediaUploadOptions());

        $this->assertArrayHasKey('media', $result);
        $this->assertArrayNotHasKey('derivatives', $result);
        $this->assertDatabaseCount('media_derivatives', 0);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_image_generates_first_class_thumbnail_derivative_and_updates_dimensions(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $png = $this->png(4, 2);
        [$workspace, $media] = $this->media('image/png', 'images/photo.png', $png);

        $result = $this->processor()->process($media, new MediaUploadOptions());
        $derivative = MediaDerivative::query()->where('media_id', $media->id)->firstOrFail();

        $this->assertSame(MediaDerivativeKind::THUMBNAIL, $derivative->kind);
        $this->assertTrue($derivative->is_primary);
        $this->assertSame('jpeg', $derivative->format);
        $this->assertStringStartsWith('.derivatives/workspace-'.$workspace->id.'/'.$media->uuid.'/', $derivative->path);
        Storage::disk('public')->assertExists($derivative->path);
        $this->assertSame($derivative->path, $result['thumbnail']);
        $this->assertSame(4, $media->fresh()->width);
        $this->assertSame(2, $media->fresh()->height);
        $this->assertSame((int) $derivative->size, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_reprocessing_is_idempotent_for_derivative_rows_and_quota(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        [$workspace, $media] = $this->media('image/png', 'images/reprocess.png', $this->png(3, 3));
        $processor = $this->processor();

        $processor->process($media, new MediaUploadOptions());
        $firstUsage = (int) $workspace->fresh()->storage_used_bytes;
        $first = MediaDerivative::query()->where('media_id', $media->id)->firstOrFail();

        $processor->process($media->fresh(), new MediaUploadOptions());

        $this->assertDatabaseCount('media_derivatives', 1);
        $this->assertSame($first->id, MediaDerivative::query()->where('media_id', $media->id)->firstOrFail()->id);
        $this->assertSame($firstUsage, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_webp_and_avif_variants_are_generated_when_supported_by_gd(): void
    {
        if (! extension_loaded('gd') || ! function_exists('imagewebp')) {
            $this->markTestSkipped('GD WebP support not available.');
        }

        config()->set('tetranyble-storage.derivatives.thumbnail.formats', function_exists('imageavif') ? ['webp', 'avif'] : ['webp']);
        config()->set('tetranyble-storage.derivatives.thumbnail.primary_format', 'webp');
        [, $media] = $this->media('image/png', 'images/formats.png', $this->png(5, 5));

        $this->processor()->process($media, new MediaUploadOptions());

        $formats = MediaDerivative::query()->where('media_id', $media->id)->pluck('format')->sort()->values()->all();
        $expected = function_exists('imageavif') ? ['avif', 'webp'] : ['webp'];
        sort($expected);
        $this->assertSame($expected, $formats);
        $this->assertSame('webp', MediaDerivative::query()->where('media_id', $media->id)->where('is_primary', true)->value('format'));
    }

    public function test_image_exceeding_pixel_limit_is_rejected_before_derivative_write(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        config()->set('tetranyble-storage.images.max_pixels', 10);
        [, $media] = $this->media('image/png', 'images/oversized.png', $this->png(4, 4));

        $this->expectException(UnsafeMediaException::class);
        try {
            $this->processor()->process($media, new MediaUploadOptions());
        } finally {
            $this->assertDatabaseCount('media_derivatives', 0);
        }
    }

    public function test_exif_orientation_reader_parses_orientation_six(): void
    {
        $reader = new ExifOrientationReader();

        $this->assertSame(6, $reader->orientation($this->jpegWithOrientation(6), 'image/jpeg'));
        $this->assertSame(1, $reader->orientation($this->jpegWithOrientation(6), 'image/png'));
    }

    public function test_orientation_normalizer_rotates_dimensions_for_orientation_six(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }

        $image = imagecreatetruecolor(4, 2);
        $normalizer = new ImageOrientationNormalizer(new ExifOrientationReader());
        $normalized = $normalizer->normalize($image, 6);

        $this->assertSame(2, imagesx($normalized));
        $this->assertSame(4, imagesy($normalized));
        imagedestroy($normalized);
    }

    /** @return array{Workspace,Media} */
    private function media(string $mime, string $path, string $binary): array
    {
        $workspace = Workspace::create(['name' => 'Derivative Workspace', 'storage_quota_bytes' => 10_000_000]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => $path,
            'mime_type' => $mime,
            'size' => strlen($binary),
            'original_name' => basename($path),
        ]);
        Storage::disk('public')->put($path, $binary);

        return [$workspace, $media];
    }

    private function processor(): MediaPostProcessor
    {
        return $this->app->make(MediaPostProcessor::class);
    }

    private function png(int $width, int $height): string
    {
        if (! extension_loaded('gd')) {
            return '';
        }
        $image = imagecreatetruecolor($width, $height);
        ob_start();
        imagepng($image);
        $binary = (string) ob_get_clean();
        imagedestroy($image);
        return $binary;
    }

    private function jpegWithOrientation(int $orientation): string
    {
        $tiff = 'MM'.pack('n', 42).pack('N', 8)
            .pack('n', 1)
            .pack('n', 0x0112).pack('n', 3).pack('N', 1).pack('n', $orientation)."\0\0"
            .pack('N', 0);
        $payload = "Exif\0\0".$tiff;
        $segment = "\xFF\xE1".pack('n', strlen($payload) + 2).$payload;
        return "\xFF\xD8".$segment."\xFF\xD9";
    }
}
