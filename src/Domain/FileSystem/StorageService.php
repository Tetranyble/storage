<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Storage\Domain\FileSystem\DTO\StorageUsage;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Models\Media;

class StorageService
{
    public function usage(Model $workspace): StorageUsage
    {
        return new StorageUsage(
            usedBytes: (int) $workspace->storage_used_bytes,
            quotaBytes: (int) $workspace->storage_quota_bytes,
        );
    }

    /**
     * Advisory quota check for callers that need to fail before doing expensive work.
     *
     * Do not rely on this method alone for correctness. increaseUsage() performs the
     * same quota check atomically in the database before incrementing usage.
     */
    public function assertCanStore(Model $workspace, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $fresh = $workspace->newQuery()->whereKey($workspace->getKey())->first();
        if (! $fresh instanceof Model) {
            throw new RuntimeException('Unable to resolve workspace while checking storage quota.');
        }

        $this->assertUsageCanStore($fresh, $bytes);
    }

    /**
     * Atomically reserve N bytes of workspace quota.
     *
     * The quota predicate and the increment are executed in one UPDATE statement,
     * so a stale in-memory Workspace instance cannot race another upload and push
     * storage_used_bytes beyond storage_quota_bytes.
     */
    public function increaseUsage(Model $workspace, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $updated = $this->atomicIncrease($workspace, $bytes);

        if ($updated !== 1) {
            $fresh = $workspace->newQuery()->whereKey($workspace->getKey())->first();
            if (! $fresh instanceof Model) {
                throw new RuntimeException('Unable to resolve workspace while reserving storage quota.');
            }

            $this->assertUsageCanStore($fresh, $bytes);

            // A concurrent release may have made quota available immediately after
            // our first UPDATE evaluated its predicate. Retry once against the new
            // database state before surfacing a contention error.
            if ($this->atomicIncrease($workspace, $bytes) !== 1) {
                throw new RuntimeException('Storage quota changed concurrently. Retry the operation.');
            }
        }

        $workspace->refresh();
    }

    /**
     * Decrease usage by N bytes, clamped at zero using portable SQL.
     */
    public function decreaseUsage(Model $workspace, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => DB::raw(
                'CASE WHEN storage_used_bytes >= '.(int) $bytes.
                ' THEN storage_used_bytes - '.(int) $bytes.
                ' ELSE 0 END'
            ),
        ]);

        $workspace->refresh();
    }

    /**
     * One-off reconciliation job: recompute usage from media records.
     */
    public function recalculateUsage(Model $workspace): void
    {
        $mediaModel = config('tetranyble-storage.models.media', Media::class);
        if (! is_string($mediaModel) || ! is_subclass_of($mediaModel, Model::class)) {
            throw new RuntimeException('The configured storage media model must be an Eloquent model.');
        }

        $query = $mediaModel::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($mediaModel), true)) {
            $query->withTrashed();
        }

        // Trashed media still owns its physical object until permanent deletion,
        // so reconciliation must include it. External attachments normally have
        // a null size and therefore do not contribute to package storage usage.
        $sum = $query
            ->where('workspace_id', $workspace->getKey())
            ->whereNotNull('path')
            ->where('path', 'not like', '%://%')
            ->where('path', 'not like', '//%')
            ->sum('size');

        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => (int) $sum,
        ]);

        $workspace->refresh();
    }

    private function atomicIncrease(Model $workspace, int $bytes): int
    {
        return $workspace->newQuery()
            ->whereKey($workspace->getKey())
            ->where('storage_quota_bytes', '>', 0)
            ->whereRaw('storage_used_bytes + ? <= storage_quota_bytes', [$bytes])
            ->update([
                'storage_used_bytes' => DB::raw('storage_used_bytes + '.(int) $bytes),
            ]);
    }

    private function assertUsageCanStore(Model $workspace, int $bytes): void
    {
        $usage = $this->usage($workspace);

        if ($usage->quotaBytes === 0) {
            throw new StorageQuotaExceededException(
                requestedBytes: $bytes,
                usedBytes: $usage->usedBytes,
                quotaBytes: $usage->quotaBytes,
                message: 'Workspace has no storage quota configured.'
            );
        }

        if ($usage->usedBytes + $bytes > $usage->quotaBytes) {
            throw new StorageQuotaExceededException(
                requestedBytes: $bytes,
                usedBytes: $usage->usedBytes,
                quotaBytes: $usage->quotaBytes
            );
        }
    }
}
