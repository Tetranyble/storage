<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tetranyble\Storage\Modules\Storage\Domain\DTO\StorageUsage;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Quota\Domain\Exceptions\StorageQuotaExceededException;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

class StorageService
{
    public function __construct(
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    public function usage(Model $workspace): StorageUsage
    {
        return new StorageUsage(
            used: new FileSize((int) $workspace->getAttribute('storage_used_bytes')),
            quota: new FileSize((int) $workspace->getAttribute('storage_quota_bytes')),
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
     * Compute authoritative billable usage from persisted media plus active
     * direct-upload reservations that have not yet materialized as Media rows.
     */
    public function calculatedUsageBytes(Model $workspace): int
    {
        $sum = Media::query()->withTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->whereNotNull('path')
            ->where('path', 'not like', '%://%')
            ->where('path', 'not like', '//%')
            ->sum('size');

        $derivatives = MediaDerivative::query()
            ->where('workspace_id', $workspace->getKey())
            ->sum('size');

        $reservedDirectUploads = DirectUploadSession::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('reserved_bytes', '>', 0)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw('1'))
                    ->from('media')
                    ->whereColumn('media.direct_upload_session_uuid', 'direct_upload_sessions.uuid');
            })
            ->sum('reserved_bytes');

        return (int) $sum + (int) $derivatives + (int) $reservedDirectUploads;
    }

    public function recalculateUsage(Model $workspace): void
    {
        $workspace->newQuery()->whereKey($workspace->getKey())->update([
            'storage_used_bytes' => $this->calculatedUsageBytes($workspace),
        ]);

        $workspace->refresh();
    }

    private function recordQuotaRejection(Model $workspace, int $requested, int $used, int $quota, string $reason): void
    {
        $dimensions = [
            'workspace_id' => (int) $workspace->getKey(),
            'reason' => $reason,
            'requested_bytes' => $requested,
            'used_bytes' => $used,
            'quota_bytes' => $quota,
        ];
        $this->telemetry?->counter('quota.rejections', 1, $dimensions);
        $this->telemetry?->event('quota.rejected', $dimensions, TelemetryLevel::WARNING);
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

        if ($usage->quota->bytes === 0) {
            $this->recordQuotaRejection($workspace, $bytes, $usage->used->bytes, $usage->quota->bytes, 'quota_not_configured');
            throw new StorageQuotaExceededException(
                requestedBytes: $bytes,
                usedBytes: $usage->used->bytes,
                quotaBytes: $usage->quota->bytes,
                message: 'Workspace has no storage quota configured.'
            );
        }

        if ($usage->used->bytes + $bytes > $usage->quota->bytes) {
            $this->recordQuotaRejection($workspace, $bytes, $usage->used->bytes, $usage->quota->bytes, 'quota_exceeded');
            throw new StorageQuotaExceededException(
                requestedBytes: $bytes,
                usedBytes: $usage->used->bytes,
                quotaBytes: $usage->quota->bytes
            );
        }
    }
}
