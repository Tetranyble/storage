<?php

namespace Tetranyble\Storage\Modules\Activity\Infrastructure;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityFeed;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;

class NullActivityFeed implements ActivityFeed
{
    public function forWorkspace(object $workspace): Collection
    {
        return (new Activity)->newCollection();
    }

    public function paginateWorkspace(
        object $workspace,
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

    public function forVersionGroup(object $media, string $groupUuid): Collection
    {
        return (new Activity)->newCollection();
    }
}
