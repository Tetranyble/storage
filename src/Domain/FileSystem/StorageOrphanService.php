<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Throwable;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Models\StorageOrphan;

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

                return true;
            }

            if (! $this->files->delete((string) $orphan->path, $disk)) {
                $this->recordFailure($orphan, 'Filesystem delete returned false.');

                return false;
            }

            $orphan->delete();

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
        $orphan->forceFill([
            'attempts' => ((int) $orphan->attempts) + 1,
            'last_error' => $message,
            'last_attempt_at' => now(),
        ])->save();
    }

    private function objectKeyHash(Disk $disk, string $path): string
    {
        return hash('sha256', $disk->value."\0".$path);
    }
}
