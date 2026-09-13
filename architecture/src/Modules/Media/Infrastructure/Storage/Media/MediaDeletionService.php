<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media;

use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Comment;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
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
        $billableBytes = array_sum(array_map(
            static fn (array $object): int => (int) ($object['size'] ?? 0),
            $objects,
        ));

        try {
            DB::transaction(function () use (
                $media,
                $objects,
                &$registered,
                $workspace,
                $workspaceId,
                $billableBytes,
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

                if ($workspace && $billableBytes > 0) {
                    $this->storage->decreaseUsage($workspace, $billableBytes);
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
        $objects = [];
        $seen = [];

        if ($media->disk instanceof Disk
            && is_string($media->path)
            && $media->path !== ''
            && ! $this->isExternalUrl($media->path)) {
            $seen[$media->disk->value."\0".$media->path] = true;
            $objects[] = [
                'disk' => $media->disk,
                'path' => $media->path,
                'size' => (int) ($media->size ?? 0),
                'reason' => 'media_delete',
            ];
        }

        foreach ($media->derivatives()->get() as $derivative) {
            if (! $derivative->getAttribute('disk') instanceof Disk || $derivative->getAttribute('path') === '') {
                continue;
            }

            $key = $derivative->disk->value."\0".$derivative->path;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $objects[] = [
                'disk' => $derivative->disk,
                'path' => $derivative->path,
                'size' => (int) $derivative->getAttribute('size'),
                'reason' => 'derivative_delete',
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
