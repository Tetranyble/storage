<?php

namespace Tetranyble\Storage\Domain\Activity;

use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class DatabaseActivityFeed implements ActivityFeed
{
    public function forWorkspace(Model $workspace): Collection
    {
        return $this->workspaceQuery($workspace)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();
    }

    public function paginateWorkspace(
        Model $workspace,
        int $page = 1,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return $this->workspaceQuery($workspace)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
    }

    public function forVersionGroup(Media $media, string $groupUuid): Collection
    {
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
}
