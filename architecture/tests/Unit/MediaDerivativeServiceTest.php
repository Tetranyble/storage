<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaDerivativeService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaDerivativeServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_derivative_create_replace_and_purge_keep_quota_exact(): void
    {
        $workspace = Workspace::create(['name' => 'Derivatives', 'storage_quota_bytes' => 1_000_000]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'source/image.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'use' => MediaPurpose::IMAGE,
        ]);
        $service = $this->app->make(MediaDerivativeService::class);

        $created = $service->persistBinary(
            $media,
            MediaDerivativeKind::THUMBNAIL,
            'default',
            'jpeg',
            'image/jpeg',
            '0123456789',
            100,
            50,
            true,
        );
        $this->assertSame(10, (int) $workspace->fresh()->storage_used_bytes);
        Storage::disk('public')->assertExists($created->path);
        $oldPath = $created->path;

        $replaced = $service->persistBinary(
            $media,
            MediaDerivativeKind::THUMBNAIL,
            'default',
            'jpeg',
            'image/jpeg',
            '1234',
            80,
            40,
            true,
        );
        $this->assertSame($created->id, $replaced->id);
        $this->assertNotSame($oldPath, $replaced->path);
        $this->assertStringContainsString(substr(hash('sha256', '1234'), 0, 16), $replaced->path);
        Storage::disk('public')->assertMissing($oldPath);
        Storage::disk('public')->assertExists($replaced->path);
        $this->assertSame(4, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(4, (int) $replaced->fresh()->size);

        $this->assertSame(1, $service->purge($media));
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertDatabaseCount('media_derivatives', 0);
        Storage::disk('public')->assertMissing($replaced->path);
    }

    public function test_primary_derivative_is_unique_per_kind(): void
    {
        $workspace = Workspace::create(['name' => 'Primary derivatives', 'storage_quota_bytes' => 1_000_000]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'source/image.jpg',
            'mime_type' => 'image/jpeg',
            'size' => 100,
            'use' => MediaPurpose::IMAGE,
        ]);
        $service = $this->app->make(MediaDerivativeService::class);

        $jpeg = $service->persistBinary($media, MediaDerivativeKind::THUMBNAIL, 'default', 'jpeg', 'image/jpeg', 'jpeg', 10, 10, true);
        $webp = $service->persistBinary($media, MediaDerivativeKind::THUMBNAIL, 'default', 'webp', 'image/webp', 'webp', 10, 10, true);

        $this->assertFalse($jpeg->fresh()->is_primary);
        $this->assertTrue($webp->fresh()->is_primary);
        $this->assertSame($webp->id, $service->primary($media, MediaDerivativeKind::THUMBNAIL)?->id);
    }
}
