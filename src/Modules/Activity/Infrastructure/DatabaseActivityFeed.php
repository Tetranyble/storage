<?php

namespace Tetranyble\Storage\Modules\Activity\Infrastructure;

use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityFeed;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DatabaseActivityFeed implements ActivityFeed
{
    public function forWorkspace(object $workspace): Collection
    {
        return $this->workspaceQuery($this->model($workspace))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function paginateWorkspace(
        object $workspace,
        int $page = 1,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return $this->workspaceQuery($this->model($workspace))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function forVersionGroup(object $media, string $groupUuid): Collection
    {
        $media = $this->model($media);

        $versionIds = Media::withTrashed()
            ->where('version_group_uuid', $groupUuid)
            ->pluck('id');

        if ($versionIds->isEmpty()) {
            return Activity::newCollection();
        }

        return Activity::query()
            ->where('subject_type', $media->getMorphClass())
            ->whereIn('subject_id', $versionIds)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    private function workspaceQuery(Model $workspace): Builder
    {
        return Activity::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('type', 'like', 'storage.%')
            ->with('subject');
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new \InvalidArgumentException('Expected an Eloquent model resource.');
        }
        return $value;
    }

}
