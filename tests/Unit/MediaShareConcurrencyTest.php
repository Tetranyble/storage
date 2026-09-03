<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaShareService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaShareConcurrencyTest extends PackageTestCase
{
    public function test_only_one_stale_request_can_consume_the_final_download_slot(): void
    {
        $workspace = Workspace::create(['name' => 'Share race']);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'shares/race.txt',
            'use' => MediaPurpose::GENERAL,
        ]);
        $share = MediaShare::create([
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'download',
            'max_downloads' => 1,
            'downloads_count' => 0,
        ]);

        $requestA = $share->fresh();
        $requestB = $share->fresh();
        $service = $this->app->make(MediaShareService::class);

        $service->consumeDownloadAccess($requestA);

        try {
            $service->consumeDownloadAccess($requestB);
            $this->fail('A second stale request must not exceed max_downloads.');
        } catch (HttpException $exception) {
            $this->assertSame(429, $exception->getStatusCode());
        }

        $this->assertSame(1, $share->fresh()->downloads_count);
    }

    public function test_concurrent_access_level_change_prevents_stale_download_consumption(): void
    {
        $workspace = Workspace::create(['name' => 'Share policy change']);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'shares/policy.txt',
            'use' => MediaPurpose::GENERAL,
        ]);
        $share = MediaShare::create([
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'download',
            'max_downloads' => 5,
            'downloads_count' => 0,
        ]);

        $staleRequest = $share->fresh();
        $share->newQuery()->whereKey($share->id)->update(['access_level' => 'view']);

        try {
            $this->app->make(MediaShareService::class)->consumeDownloadAccess($staleRequest);
            $this->fail('A stale download permission must not survive a concurrent downgrade to view-only.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, $share->fresh()->downloads_count);
    }

    public function test_view_share_never_consumes_a_download_slot(): void
    {
        $workspace = Workspace::create(['name' => 'View only']);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'shares/view.txt',
            'use' => MediaPurpose::GENERAL,
        ]);
        $share = MediaShare::create([
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'view',
            'max_downloads' => 1,
            'downloads_count' => 0,
        ]);

        try {
            $this->app->make(MediaShareService::class)->consumeDownloadAccess($share);
            $this->fail('View-only shares must not allow download consumption.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $this->assertSame(0, $share->fresh()->downloads_count);
    }
}
