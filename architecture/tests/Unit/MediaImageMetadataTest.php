<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;

class MediaImageMetadataTest extends PackageTestCase
{
    public function test_image_dimensions_are_populated_by_post_processing_not_model_save_hooks(): void
    {
        if (! extension_loaded('gd')) {
            $this->markTestSkipped('GD extension not available.');
        }
        Storage::fake(Disk::PUBLIC->value);

        $binary = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAGQAAAAyCAIAAAAlV+npAAAAjUlEQVR4nO3QQQ3AIADAQEA5hCP7S5M8LkQkCvr2zpk5n7sD9zErMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzArMCswKzD7Abf3A9ZLA56EAAAAAElFTkSuQmCC');

        $path = 'tests/social-preview.png';
        Storage::disk(Disk::PUBLIC->value)->put($path, $binary);

        $media = Media::create([
            'disk' => Disk::PUBLIC,
            'path' => $path,
            'mime_type' => 'image/png',
            'use' => MediaPurpose::IMAGE,
            'current' => true,
        ]);

        $this->assertNull($media->width);
        $this->assertNull($media->height);

        $this->app->make(MediaPostProcessor::class)->process($media, new MediaUploadOptions());

        $media->refresh();
        $this->assertSame(100, $media->width);
        $this->assertSame(50, $media->height);
    }
}
