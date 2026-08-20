<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;

class MediaImageMetadataTest extends PackageTestCase
{
    public function test_it_populates_width_and_height_for_local_image_media(): void
    {
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

        $this->assertSame(100, $media->width);
        $this->assertSame(50, $media->height);
    }
}
