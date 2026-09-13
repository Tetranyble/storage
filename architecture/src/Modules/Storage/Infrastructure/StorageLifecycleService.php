<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Throwable;

/**
 * Coordinates the non-transactional object store with database-backed quota.
 *
 * The commit callback MUST contain only the database mutation that establishes
 * ownership of the object. Post-commit work such as activity logging or image
 * processing belongs after this service returns successfully.
 */
class StorageLifecycleService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly StorageOrphanService $orphans,
        private readonly ?StorageTelemetry $telemetry = null,
    ) {}

    /**
     * Reserve quota first, then write the object, then commit its DB owner.
     * Any failure before commit removes the object and releases the reservation.
     *
     * @template T
     *
     * @param  Closure(): string  $store
     * @param  Closure(string): T  $commit
     * @return T
     */
    public function storeAndCommit(
        ?Model $workspace,
        Disk $disk,
        int $size,
        Closure $store,
        Closure $commit,
        string $rollbackReason = 'upload_rollback',
        ?string $expectedPath = null,
    ): mixed {
        $reserved = false;
        $storedPath = null;

        try {
            if ($workspace && $size > 0) {
                $this->storage->increaseUsage($workspace, $size);
                $reserved = true;
            }

            $storedPath = $store();
            if (! is_string($storedPath) || trim($storedPath) === '') {
                throw new RuntimeException('Storage write did not return a valid object path.');
            }

            return $commit($storedPath);
        } catch (Throwable $exception) {
            $this->recordFailure($workspace, $disk, $size, $rollbackReason, $exception);
            $rollbackPath = is_string($storedPath) && $storedPath !== '' ? $storedPath : $expectedPath;
            if (is_string($rollbackPath) && $rollbackPath !== '') {
                $this->orphans->deleteOrTrack(
                    $disk,
                    $rollbackPath,
                    $workspace?->getKey() ? (int) $workspace->getKey() : null,
                    $size > 0 ? $size : null,
                    $rollbackReason,
                );
            }

            if ($reserved && $workspace) {
                $this->storage->decreaseUsage($workspace, $size);
            }

            throw $exception;
        }
    }

    /**
     * Commit a physical object that already exists (for example a streamed
     * remote import). If quota or DB persistence fails, remove/track the object.
     *
     * @template T
     *
     * @param  Closure(): T  $commit
     * @return T
     */
    public function commitExisting(
        ?Model $workspace,
        Disk $disk,
        string $storedPath,
        int $size,
        Closure $commit,
        string $rollbackReason = 'upload_rollback',
        ?string $expectedPath = null,
    ): mixed {
        $reserved = false;

        try {
            if ($workspace && $size > 0) {
                $this->storage->increaseUsage($workspace, $size);
                $reserved = true;
            }

            return $commit();
        } catch (Throwable $exception) {
            $this->recordFailure($workspace, $disk, $size, $rollbackReason, $exception);
            $this->orphans->deleteOrTrack(
                $disk,
                $storedPath,
                $workspace?->getKey() ? (int) $workspace->getKey() : null,
                $size > 0 ? $size : null,
                $rollbackReason,
            );

            if ($reserved && $workspace) {
                $this->storage->decreaseUsage($workspace, $size);
            }

            throw $exception;
        }
    }

    private function recordFailure(?Model $workspace, Disk $disk, int $size, string $reason, Throwable $exception): void
    {
        $context = [
            'workspace_id' => $workspace?->getKey() ? (int) $workspace->getKey() : null,
            'disk' => $disk->value,
            'size_bytes' => $size,
            'reason' => $reason,
            'exception' => $exception::class,
        ];
        $this->telemetry?->counter('uploads.failures', 1, ['reason' => $reason, 'disk' => $disk->value]);
        $this->telemetry?->event('upload.lifecycle_failed', $context, TelemetryLevel::ERROR);
    }
}
