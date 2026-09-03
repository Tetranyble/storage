<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Models\Comment;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\StorageOrphan;
use Tetranyble\Storage\Support\StorageConfig;

/**
 * Owns the physical/database lifecycle for permanently deleting one media item.
 *
 * Database truth is removed first inside a transaction while every physical
 * object is registered as pending cleanup. Physical deletion happens only after
 * commit. If object storage is unavailable, the durable orphan rows remain for
 * storage:cleanup-orphans instead of leaving a live Media row pointing at a
 * missing object.
 */
class MediaDeletionService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly StorageOrphanService $orphans,
    ) {}

    public function delete(Media $media): void
    {
        $objects = $this->storedObjects($media);
        $registered = [];
        $workspace = $media->workspace_id ? StorageConfig::findWorkspace($media->workspace_id) : null;
        $workspaceId = $media->workspace_id ? (int) $media->workspace_id : null;
        $size = (int) ($media->size ?? 0);

        try {
            DB::transaction(function () use (
                $media,
                $objects,
                &$registered,
                $workspace,
                $workspaceId,
                $size,
            ): void {
                foreach ($objects as $object) {
                    $registered[] = $this->orphans->register(
                        $object['disk'],
                        $object['path'],
                        $workspaceId,
                        $object['size'],
                        $object['reason'],
                    );
                }

                // Polymorphic relations do not have database FKs back to Media.
                // Remove them in the same transaction so permanent deletion cannot
                // leave package-owned relational orphans behind.
                $media->shares()->delete();
                $media->collaborators()->delete();
                $media->stars()->delete();
                Comment::withTrashed()
                    ->where('commentable_type', $media->getMorphClass())
                    ->where('commentable_id', $media->getKey())
                    ->forceDelete();

                if ($workspace && $size > 0) {
                    $this->storage->decreaseUsage($workspace, $size);
                }

                if (method_exists($media, 'forceDelete')) {
                    $media->forceDelete();
                } else {
                    $media->delete();
                }
            });
        } catch (\Throwable $exception) {
            // decreaseUsage() refreshes the in-memory model while the transaction
            // is open. If the transaction rolls back, refresh again so callers do
            // not continue with a stale, temporarily-decremented quota value.
            if ($workspace) {
                $workspace->refresh();
            }

            throw $exception;
        }

        /** @var StorageOrphan $orphan */
        foreach ($registered as $orphan) {
            // Physical cleanup is deliberately post-commit and retriable.
            $this->orphans->cleanup($orphan->fresh() ?? $orphan);
        }
    }

    /**
     * @return array<int, array{disk: Disk, path: string, size: int|null, reason: string}>
     */
    private function storedObjects(Media $media): array
    {
        if (! $media->disk instanceof Disk) {
            return [];
        }

        $objects = [];
        $seen = [];
        $workspaceBytes = (int) ($media->size ?? 0);

        foreach ([
            [$media->path, $workspaceBytes > 0 ? $workspaceBytes : null, 'media_delete'],
            [$media->thumbnail_path, null, 'thumbnail_delete'],
        ] as [$path, $size, $reason]) {
            if (! is_string($path) || $path === '' || $this->isExternalUrl($path) || isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $objects[] = [
                'disk' => $media->disk,
                'path' => $path,
                'size' => $size,
                'reason' => $reason,
            ];
        }

        return $objects;
    }

    private function isExternalUrl(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
}
