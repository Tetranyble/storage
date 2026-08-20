<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Domain\FileSystem\DTO\StorageUsage;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\StorageQuotaExceededException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
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
     * Hard check: throw if workspace cannot store requested bytes.
     */
    public function assertCanStore(Model $workspace, int $bytes): void
    {
        $usage = $this->usage($workspace);

        if ($bytes <= 0) {
            return;
        }

        if ($usage->quotaBytes === 0) {
            // 0 quota could be "unlimited" in some systems; if you want that,
            // just return here instead of throwing.
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

    /**
     * Increase usage by N bytes.
     */
    public function increaseUsage(Model $workspace, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => DB::raw('storage_used_bytes + '.(int) $bytes),
        ]);

        $workspace->refresh();
    }

    /**
     * Decrease usage by N bytes (clamped at 0).
     */
    public function decreaseUsage(Model $workspace, int $bytes): void
    {
        if ($bytes <= 0) {
            return;
        }

        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => DB::raw('MAX(storage_used_bytes - '.(int) $bytes.', 0)'),
        ]);

        $workspace->refresh();
    }

    /**
     * One-off reconciliation job: recompute usage from media records.
     * Handy as a scheduled task once a day or manual admin action.
     */
    public function recalculateUsage(Model $workspace): void
    {
        $mediaModel = config('tetranyble-storage.models.media', Media::class);
        if (! is_string($mediaModel) || ! is_subclass_of($mediaModel, Model::class)) {
            throw new RuntimeException('The configured storage media model must be an Eloquent model.');
        }

        $sum = $mediaModel::query()
            ->where('workspace_id', $workspace->getKey())
            // ->whereNull('deleted_at')  // trash does NOT count; adjust if you prefer
            ->sum('size');

        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => (int) $sum,
        ]);

        $workspace->refresh();
    }
}
