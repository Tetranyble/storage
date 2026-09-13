<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure;

use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Policy\OrphanCleanupRetryPolicy;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Throwable;

/**
 * Durable registry for physical objects whose owning DB mutation has already
 * completed (or whose rollback cleanup failed).
 *
 * Orphans are intentionally retriable. A failed filesystem delete must not
 * resurrect a deleted Media row or corrupt quota accounting.
 */
class StorageOrphanService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly ?StorageTelemetry $telemetry = null,
        private readonly ?OrphanCleanupRetryPolicy $retryPolicy = null,
    ) {}

    public function register(
        Disk $disk,
        string $path,
        ?int $workspaceId = null,
        ?int $size = null,
        string $reason = 'cleanup',
    ): StorageOrphan {
        $hash = $this->objectKeyHash($disk, $path);

        /** @var StorageOrphan $orphan */
        $orphan = StorageOrphan::query()->updateOrCreate(
            ['object_key_hash' => $hash],
            [
                'workspace_id' => $workspaceId,
                'disk' => $disk->value,
                'path' => $path,
                'size' => $size,
                'reason' => $reason,
            ],
        );

        $this->telemetry?->counter('orphans.registered', 1, ['reason' => $reason, 'disk' => $disk->value]);
        $this->telemetry?->event('orphan.registered', [
            'workspace_id' => $workspaceId,
            'disk' => $disk->value,
            'size_bytes' => $size,
            'reason' => $reason,
        ], TelemetryLevel::WARNING);

        return $orphan;
    }

    /**
     * Best-effort cleanup used during compensation paths. If deletion fails,
     * persist enough metadata for a later cleanup command to retry it.
     */
    public function deleteOrTrack(
        Disk $disk,
        string $path,
        ?int $workspaceId = null,
        ?int $size = null,
        string $reason = 'cleanup',
    ): bool {
        try {
            // Cleanup is idempotent: an already-missing path is success, not an
            // orphan that would otherwise retry forever.
            if (! $this->files->exists($path, $disk)) {
                $this->forget($disk, $path);

                return true;
            }

            if ($this->files->delete($path, $disk)) {
                $this->forget($disk, $path);

                return true;
            }

            $this->recordFailure(
                $this->register($disk, $path, $workspaceId, $size, $reason),
                'Filesystem delete returned false.'
            );
        } catch (Throwable $exception) {
            try {
                $this->recordFailure(
                    $this->register($disk, $path, $workspaceId, $size, $reason),
                    $exception->getMessage(),
                );
            } catch (Throwable) {
                // Preserve the original business exception. Orphan tracking is
                // a recovery aid and must never hide the mutation that failed.
            }
        }

        return false;
    }

    public function cleanup(StorageOrphan $orphan): bool
    {
        $disk = is_string($orphan->disk) ? Disk::tryFrom($orphan->disk) : null;
        if (! $disk) {
            $this->recordFailure($orphan, 'Unknown storage disk: '.(string) $orphan->disk);

            return false;
        }

        try {
            if (! $this->files->exists((string) $orphan->path, $disk)) {
                $orphan->delete();
                $this->telemetry?->counter('orphans.cleaned');

                return true;
            }

            if (! $this->files->delete((string) $orphan->path, $disk)) {
                $this->recordFailure($orphan, 'Filesystem delete returned false.');

                return false;
            }

            $orphan->delete();
            $this->telemetry?->counter('orphans.cleaned');

            return true;
        } catch (Throwable $exception) {
            $this->recordFailure($orphan, $exception->getMessage());

            return false;
        }
    }

    public function forget(Disk $disk, string $path): void
    {
        StorageOrphan::query()
            ->where('object_key_hash', $this->objectKeyHash($disk, $path))
            ->delete();
    }

    private function recordFailure(StorageOrphan $orphan, string $message): void
    {
        $this->telemetry?->counter('orphans.cleanup_failures');
        $this->telemetry?->event('orphan.cleanup_failed', [
            'workspace_id' => $orphan->workspace_id !== null ? (int) $orphan->workspace_id : null,
            'disk' => (string) $orphan->disk,
            'attempts' => ((int) $orphan->attempts) + 1,
            'error_class' => 'storage_cleanup_failure',
        ], TelemetryLevel::ERROR);

        $attempts = ((int) $orphan->attempts) + 1;
        $now = now();
        $abandoned = $this->policy()->shouldAbandon($attempts);
        $retryAt = $abandoned
            ? null
            : $this->policy()->retryAt($now->toDateTimeImmutable(), $attempts);

        $orphan->forceFill([
            'attempts' => $attempts,
            'last_error' => $message,
            'last_attempt_at' => $now,
            'next_attempt_at' => $retryAt,
            'abandoned_at' => $abandoned ? $now : null,
        ])->save();

        if ($abandoned) {
            $this->telemetry?->counter('orphans.abandoned');
            $this->telemetry?->event('orphan.abandoned', [
                'workspace_id' => $orphan->workspace_id !== null ? (int) $orphan->workspace_id : null,
                'disk' => (string) $orphan->disk,
                'attempts' => $attempts,
                'reason' => (string) $orphan->reason,
            ], TelemetryLevel::CRITICAL);
        }
    }

    private function policy(): OrphanCleanupRetryPolicy
    {
        if ($this->retryPolicy) {
            return $this->retryPolicy;
        }

        $configured = config('tetranyble-storage.orphan_cleanup.backoff', [60, 300, 1800, 7200, 21600]);
        $backoff = is_array($configured)
            ? array_values(array_map(static fn ($seconds): int => max(1, (int) $seconds), $configured))
            : [60, 300, 1800, 7200, 21600];

        return new OrphanCleanupRetryPolicy(
            max(1, (int) config('tetranyble-storage.orphan_cleanup.max_attempts', 10)),
            $backoff,
        );
    }

    private function objectKeyHash(Disk $disk, string $path): string
    {
        return hash('sha256', $disk->value."\0".$path);
    }
}
