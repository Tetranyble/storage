<?php

namespace Tetranyble\Storage\Contracts;

use Tetranyble\Storage\Models\Media;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

interface ActivityFeed
{
    public function forWorkspace(Model $workspace): Collection;

    public function paginateWorkspace(
        Model $workspace,
        int $page = 1,
        int $perPage = 50,
    ): LengthAwarePaginator;

    public function forVersionGroup(Media $media, string $groupUuid): Collection;
}
