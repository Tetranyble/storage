<?php

namespace Tetranyble\Storage\Modules\Health\Infrastructure\Application;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\Health\Domain\DTO\HealthCheckResult;
use Tetranyble\Storage\Modules\Health\Domain\Enums\HealthStatus;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Support\StorageConfig;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

final class StorageHealthService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    /** @return list<HealthCheckResult> */
    public function check(?int $workspaceId = null): array
    {
        $results = [
            $this->safely('database', fn () => $this->database()),
            $this->safely('storage', fn () => $this->storageConnectivity($workspaceId)),
            $this->safely('quota_drift', fn () => $this->quotaDrift($workspaceId)),
            $this->safely('orphans', fn () => $this->orphans($workspaceId)),
            $this->safely('resumable_uploads', fn () => $this->resumableUploads($workspaceId)),
            $this->safely('direct_uploads', fn () => $this->directUploads($workspaceId)),
            $this->safely('processing', fn () => $this->processing($workspaceId)),
            $this->safely('connected_drives', fn () => $this->connectedDrives($workspaceId)),
        ];

        $worst = HealthStatus::OK;
        foreach ($results as $result) {
            $dimensions = ['workspace_id' => $workspaceId];
            $this->telemetry?->gauge('health.'.$result->name.'.severity', $result->status->severity(), $dimensions);
            foreach ($result->details as $key => $value) {
                if (is_int($value) || is_float($value)) {
                    $this->telemetry?->gauge('health.'.$result->name.'.'.$key, $value, $dimensions);
                }
            }
            if ($result->status->severity() > $worst->severity()) {
                $worst = $result->status;
            }
        }
        $this->telemetry?->event('health.completed', [
            'workspace_id' => $workspaceId,
            'status' => $worst->value,
            'check_count' => count($results),
        ], match ($worst) {
            HealthStatus::CRITICAL => TelemetryLevel::ERROR,
            HealthStatus::WARNING => TelemetryLevel::WARNING,
            default => TelemetryLevel::INFO,
        });

        return $results;
    }

    private function database(): HealthCheckResult
    {
        DB::connection()->select('select 1');

        return new HealthCheckResult('database', HealthStatus::OK, 'Database connection is healthy.');
    }

    private function storageConnectivity(?int $workspaceId): HealthCheckResult
    {
        $configured = config('tetranyble-storage.observability.health.disks', []);
        $disks = is_array($configured) ? array_values(array_filter($configured, 'is_string')) : [];

        if ($disks === []) {
            $disks[] = (string) config('filesystems.default', 'local');

            // Keep the storage probe useful even when the database itself is unhealthy.
            // Referenced-disk discovery is an enrichment, not a prerequisite for probing
            // the explicitly configured/default object store.
            try {
                $disks = array_merge(
                    $disks,
                    Media::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
                        ->whereNotNull('disk')->distinct()->limit(10)->pluck('disk')->all(),
                    UploadSession::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
                        ->whereNotNull('disk')->distinct()->limit(10)->pluck('disk')->all(),
                    DirectUploadSession::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))
                        ->whereNotNull('disk')->distinct()->limit(10)->pluck('disk')->all(),
                );
            } catch (Throwable) {
                // The dedicated database health check will report this failure.
            }
        }

        $disks = array_values(array_unique(array_filter($disks)));
        $healthy = 0;
        $failed = [];
        $probe = (string) config('tetranyble-storage.observability.health.probe_key', '.tetranyble-storage-health-probe');

        foreach ($disks as $disk) {
            if (! config()->has("filesystems.disks.{$disk}")) {
                $failed[] = $disk;
                continue;
            }

            try {
                Storage::disk($disk)->exists($probe);
                $healthy++;
            } catch (Throwable) {
                $failed[] = $disk;
            }
        }

        if ($failed !== []) {
            return new HealthCheckResult(
                'storage',
                HealthStatus::CRITICAL,
                sprintf('%d storage disk(s) failed connectivity checks.', count($failed)),
                ['checked_disks' => count($disks), 'healthy_disks' => $healthy, 'failed_disks' => $failed],
            );
        }

        return new HealthCheckResult(
            'storage',
            HealthStatus::OK,
            sprintf('%d storage disk(s) responded successfully.', $healthy),
            ['checked_disks' => count($disks), 'healthy_disks' => $healthy],
        );
    }

    private function quotaDrift(?int $workspaceId): HealthCheckResult
    {
        $workspaceClass = StorageConfig::workspaceModelClass();
        $limit = max(1, (int) config('tetranyble-storage.observability.health.max_workspaces', 1000));
        $tolerance = max(0, (int) config('tetranyble-storage.observability.health.quota_drift_tolerance_bytes', 0));

        $query = $workspaceClass::query();
        if ($workspaceId) {
            $query->whereKey($workspaceId);
        }

        $totalWorkspaces = (clone $query)->count();
        $truncated = $totalWorkspaces > $limit;
        $checked = 0;
        $drifted = 0;
        $overQuota = 0;
        $maxAbsoluteDrift = 0;

        foreach ($query->orderBy((new $workspaceClass())->getKeyName())->limit($limit)->get() as $workspace) {
            if (! $workspace instanceof Model) {
                continue;
            }
            $checked++;
            $expected = $this->storage->calculatedUsageBytes($workspace);
            $actual = (int) $workspace->storage_used_bytes;
            $drift = abs($actual - $expected);
            $maxAbsoluteDrift = max($maxAbsoluteDrift, $drift);
            if ($drift > $tolerance) {
                $drifted++;
            }
            $quota = (int) $workspace->storage_quota_bytes;
            if ($quota > 0 && $actual > $quota) {
                $overQuota++;
            }
        }

        $status = $overQuota > 0 ? HealthStatus::CRITICAL : (($drifted > 0 || $truncated) ? HealthStatus::WARNING : HealthStatus::OK);

        return new HealthCheckResult(
            'quota_drift',
            $status,
            $overQuota > 0
                ? sprintf('%d workspace(s) are over quota.', $overQuota)
                : ($drifted > 0 ? sprintf('%d workspace(s) have usage-counter drift.', $drifted) : ($truncated ? 'Quota drift scan reached the configured workspace limit.' : 'Workspace quota counters match authoritative usage.')),
            [
                'total_workspaces' => $totalWorkspaces,
                'checked_workspaces' => $checked,
                'truncated' => $truncated ? 1 : 0,
                'drifted_workspaces' => $drifted,
                'over_quota_workspaces' => $overQuota,
                'max_absolute_drift_bytes' => $maxAbsoluteDrift,
            ],
        );
    }

    private function orphans(?int $workspaceId): HealthCheckResult
    {
        $query = StorageOrphan::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId));
        $count = (clone $query)->count();
        $failed = (clone $query)->where('attempts', '>', 0)->count();
        $abandoned = (clone $query)->whereNotNull('abandoned_at')->count();
        $warningAt = max(1, (int) config('tetranyble-storage.observability.health.orphan_warning_count', 1));
        $criticalAt = max($warningAt, (int) config('tetranyble-storage.observability.health.orphan_critical_count', 100));
        $status = ($abandoned > 0 || $count >= $criticalAt) ? HealthStatus::CRITICAL : ($count >= $warningAt ? HealthStatus::WARNING : HealthStatus::OK);

        return new HealthCheckResult('orphans', $status,
            $count === 0 ? 'No storage orphans are waiting for cleanup.' : sprintf('%d storage orphan(s) are waiting for cleanup.', $count),
            ['orphan_count' => $count, 'previously_failed_count' => $failed, 'abandoned_count' => $abandoned]);
    }

    private function resumableUploads(?int $workspaceId): HealthCheckResult
    {
        $minutes = max(1, (int) config('tetranyble-storage.observability.health.stuck_upload_minutes', 30));
        $active = [UploadSessionStatus::PENDING->value, UploadSessionStatus::UPLOADING->value, UploadSessionStatus::ASSEMBLING->value];
        $base = UploadSession::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId))->whereIn('status', $active);
        $stuck = (clone $base)->where('updated_at', '<', now()->subMinutes($minutes))->count();
        $expired = (clone $base)->whereNotNull('session_expires_at')->where('session_expires_at', '<', now())->count();
        $status = ($stuck + $expired) > 0 ? HealthStatus::WARNING : HealthStatus::OK;

        return new HealthCheckResult('resumable_uploads', $status,
            $status === HealthStatus::OK ? 'No stuck resumable upload sessions detected.' : 'Resumable upload sessions require cleanup or investigation.',
            ['active_count' => (clone $base)->count(), 'stuck_count' => $stuck, 'expired_active_count' => $expired]);
    }

    private function directUploads(?int $workspaceId): HealthCheckResult
    {
        $minutes = max(1, (int) config('tetranyble-storage.observability.health.stuck_direct_upload_minutes', 30));
        $active = [DirectUploadStatus::PENDING->value, DirectUploadStatus::UPLOADING->value, DirectUploadStatus::FINALIZING->value];
        $query = DirectUploadSession::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId));
        $base = (clone $query)->whereIn('status', $active);
        $stuck = (clone $base)->where('updated_at', '<', now()->subMinutes($minutes))->count();
        $expired = (clone $base)->whereNotNull('session_expires_at')->where('session_expires_at', '<', now())->count();
        $cleanup = (clone $query)->where('cleanup_pending', true)->count();
        $failed = (clone $query)->where('status', DirectUploadStatus::FAILED->value)->count();
        $status = ($stuck + $expired + $cleanup) > 0 ? HealthStatus::WARNING : HealthStatus::OK;

        return new HealthCheckResult('direct_uploads', $status,
            $status === HealthStatus::OK ? 'Direct-upload sessions are healthy.' : 'Direct-upload sessions require cleanup or investigation.',
            ['active_count' => (clone $base)->count(), 'stuck_count' => $stuck, 'expired_active_count' => $expired, 'cleanup_pending_count' => $cleanup, 'failed_count' => $failed]);
    }

    private function processing(?int $workspaceId): HealthCheckResult
    {
        $staleMinutes = max(1, (int) config('tetranyble-storage.processing.stale_after_minutes', 15));
        $backlogMinutes = max(1, (int) config('tetranyble-storage.observability.health.processing_backlog_minutes', 30));
        $query = Media::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId));
        $stale = (clone $query)->where('processing_status', MediaProcessingStatus::PROCESSING->value)
            ->whereNotNull('processing_started_at')->where('processing_started_at', '<', now()->subMinutes($staleMinutes))->count();
        $backlog = (clone $query)->whereIn('processing_status', [MediaProcessingStatus::PENDING->value, MediaProcessingStatus::QUEUED->value])
            ->where('updated_at', '<', now()->subMinutes($backlogMinutes))->count();
        $dispatchLease = max(1, (int) config('tetranyble-storage.processing.dispatch_lease_seconds', 300));
        $staleDispatch = (clone $query)->where('processing_status', MediaProcessingStatus::QUEUED->value)
            ->where(function ($queued) use ($dispatchLease): void {
                $queued->whereNull('processing_dispatched_at')
                    ->orWhere('processing_dispatched_at', '<', now()->subSeconds($dispatchLease));
            })->count();
        $failed = (clone $query)->where('processing_status', MediaProcessingStatus::FAILED->value)->count();
        $blocked = (clone $query)->where('processing_status', MediaProcessingStatus::BLOCKED->value)->count();
        $status = ($stale + $staleDispatch) > 0 ? HealthStatus::CRITICAL : (($backlog + $failed) > 0 ? HealthStatus::WARNING : HealthStatus::OK);

        return new HealthCheckResult('processing', $status,
            $status === HealthStatus::OK ? 'Media processing queue has no stale or failed work.' : 'Media processing requires attention.',
            ['stale_processing_count' => $stale, 'stale_dispatch_count' => $staleDispatch, 'backlog_count' => $backlog, 'failed_count' => $failed, 'blocked_count' => $blocked]);
    }

    private function connectedDrives(?int $workspaceId): HealthCheckResult
    {
        $query = ConnectedDrive::query()->when($workspaceId, fn ($q) => $q->where('workspace_id', $workspaceId));
        $errors = (clone $query)->where('status', ConnectedDriveStatus::ERROR->value)->count();
        $expired = (clone $query)->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->whereNotNull('token_expires_at')->where('token_expires_at', '<', now())->count();
        $status = ($errors + $expired) > 0 ? HealthStatus::WARNING : HealthStatus::OK;

        return new HealthCheckResult('connected_drives', $status,
            $status === HealthStatus::OK ? 'Connected-drive records are healthy.' : 'Connected drives require reconnection or token refresh.',
            ['error_count' => $errors, 'expired_token_count' => $expired, 'connected_count' => (clone $query)->where('status', ConnectedDriveStatus::CONNECTED->value)->count()]);
    }

    private function safely(string $name, callable $check): HealthCheckResult
    {
        try {
            return $check();
        } catch (Throwable) {
            return new HealthCheckResult($name, HealthStatus::CRITICAL, 'Health check could not be completed safely.');
        }
    }
}
