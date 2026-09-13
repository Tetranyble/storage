<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application\Bulk;

use Illuminate\Database\Eloquent\Model;
use Throwable;
use Tetranyble\Storage\Modules\Media\Application\DeleteMedia;
use Tetranyble\Storage\Modules\Media\Application\MoveMedia;
use Tetranyble\Storage\Modules\Media\Application\RestoreMedia;
use Tetranyble\Storage\Modules\Media\Application\TrashMedia;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\StorageException;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;

/**
 * Bounded bulk orchestration over the canonical single-item use cases.
 *
 * No bulk path bypasses workspace scoping, ACLs, lifecycle compensation,
 * activity/events, derivative cleanup, or quota accounting.
 */
final class BulkMediaService
{
    public function __construct(
        private readonly TrashMedia $trash,
        private readonly RestoreMedia $restore,
        private readonly DeleteMedia $delete,
        private readonly MoveMedia $move,
    ) {}

    /** @param list<int|string> $mediaIds */
    public function trash(Model $workspace, array $mediaIds, ?Model $actor = null): array
    {
        return $this->run($workspace, $mediaIds, true, function (Media $media) use ($workspace, $actor): void {
            $this->trash->handle($workspace, $media, $actor);
        });
    }

    /** @param list<int|string> $mediaIds */
    public function restore(Model $workspace, array $mediaIds, ?Model $actor = null): array
    {
        return $this->run($workspace, $mediaIds, true, function (Media $media) use ($workspace, $actor): void {
            $this->restore->handle($workspace, $media, $actor);
        });
    }

    /** @param list<int|string> $mediaIds */
    public function delete(Model $workspace, array $mediaIds, ?Model $actor = null): array
    {
        return $this->run($workspace, $mediaIds, true, function (Media $media) use ($workspace, $actor): void {
            $this->delete->handle($workspace, $media, $actor);
        });
    }

    /** @param list<int|string> $mediaIds */
    public function move(Model $workspace, array $mediaIds, ?int $folderId, ?Model $actor = null): array
    {
        return $this->run($workspace, $mediaIds, false, function (Media $media) use ($workspace, $folderId, $actor): void {
            $this->move->handle($workspace, $media, $folderId, $actor);
        });
    }

    /**
     * @param list<int|string> $mediaIds
     * @param callable(Media):void $operation
     */
    private function run(Model $workspace, array $mediaIds, bool $withTrashed, callable $operation): array
    {
        $limit = max(1, (int) config('tetranyble-storage.bulk.max_items', 100));
        $ids = array_values(array_unique(array_slice($mediaIds, 0, $limit)));
        $results = [];
        $succeeded = 0;

        foreach ($ids as $id) {
            $query = Media::query()->where('workspace_id', $workspace->getKey());
            if ($withTrashed) {
                $query->withTrashed();
            }

            $media = $query->whereKey($id)->first();
            if (! $media instanceof Media) {
                $results[] = ['id' => $id, 'status' => 'not_found'];
                continue;
            }

            try {
                $operation($media);
                $succeeded++;
                $results[] = ['id' => $id, 'status' => 'ok'];
            } catch (StorageException $exception) {
                $results[] = [
                    'id' => $id,
                    'status' => 'failed',
                    'error' => class_basename($exception),
                ];
            } catch (Throwable) {
                $results[] = ['id' => $id, 'status' => 'failed', 'error' => 'operation_failed'];
            }
        }

        return [
            'requested' => count($mediaIds),
            'accepted' => count($ids),
            'succeeded' => $succeeded,
            'failed' => count($ids) - $succeeded,
            'truncated' => count($mediaIds) > $limit,
            'max_items' => $limit,
            'results' => $results,
        ];
    }
}
