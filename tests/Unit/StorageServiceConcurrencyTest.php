<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\FileSystem\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class StorageServiceConcurrencyTest extends PackageTestCase
{
    public function test_atomic_increase_rechecks_quota_against_database_state(): void
    {
        $workspace = Workspace::create([
            'name' => 'Quota race',
            'storage_quota_bytes' => 10,
            'storage_used_bytes' => 0,
        ]);

        // Simulate two requests that both loaded the workspace before either wrote.
        $requestA = $workspace->fresh();
        $requestB = $workspace->fresh();
        $service = $this->app->make(StorageService::class);

        $service->increaseUsage($requestA, 8);

        try {
            $service->increaseUsage($requestB, 8);
            $this->fail('The second stale reservation should have been rejected.');
        } catch (StorageQuotaExceededException $exception) {
            $this->assertSame(8, $exception->usedBytes);
            $this->assertSame(10, $exception->quotaBytes);
        }

        $this->assertSame(8, $workspace->fresh()->storage_used_bytes);
    }

    public function test_assert_can_store_uses_fresh_database_usage_not_stale_model_state(): void
    {
        $workspace = Workspace::create([
            'name' => 'Fresh quota check',
            'storage_quota_bytes' => 10,
            'storage_used_bytes' => 0,
        ]);
        $stale = $workspace->fresh();
        $workspace->newQuery()->whereKey($workspace->id)->update(['storage_used_bytes' => 9]);

        $this->expectException(StorageQuotaExceededException::class);

        $this->app->make(StorageService::class)->assertCanStore($stale, 2);
    }

    public function test_decrease_usage_clamps_to_zero_portably(): void
    {
        $workspace = Workspace::create([
            'name' => 'Clamp usage',
            'storage_quota_bytes' => 10,
            'storage_used_bytes' => 5,
        ]);

        $this->app->make(StorageService::class)->decreaseUsage($workspace, 8);

        $this->assertSame(0, $workspace->fresh()->storage_used_bytes);
    }
}
