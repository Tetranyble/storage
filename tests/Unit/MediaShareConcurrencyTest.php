<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadLimitReachedException;
use Tetranyble\Storage\Modules\Sharing\Domain\Exceptions\ShareDownloadNotAllowedException;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
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
        } catch (ShareDownloadLimitReachedException) {
            $this->addToAssertionCount(1);
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
        } catch (ShareDownloadNotAllowedException) {
            $this->addToAssertionCount(1);
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
        } catch (ShareDownloadNotAllowedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $share->fresh()->downloads_count);
    }
}
