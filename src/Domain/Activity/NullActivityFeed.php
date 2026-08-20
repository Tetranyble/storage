<?php

namespace Tetranyble\Storage\Domain\Activity;

use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

class NullActivityFeed implements ActivityFeed
{
    public function forWorkspace(Model $workspace): Collection
    {
        return (new Activity())->newCollection();
    }

    public function paginateWorkspace(
        Model $workspace,
        int $page = 1,
        int $perPage = 50,
    ): LengthAwarePaginator {
        return new Paginator(
            items: collect(),
            total: 0,
            perPage: $perPage,
            currentPage: $page,
        );
    }

    public function forVersionGroup(Media $media, string $groupUuid): Collection
    {
        return (new Activity())->newCollection();
    }
}
