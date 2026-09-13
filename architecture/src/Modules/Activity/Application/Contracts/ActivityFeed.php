<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Activity\Application\Contracts;

interface ActivityFeed
{
    /** @return iterable<object> */
    public function forWorkspace(object $workspace): iterable;

    /** Pagination is intentionally opaque to the core; the HTTP adapter serializes it. */
    public function paginateWorkspace(object $workspace, int $page = 1, int $perPage = 50): object;

    /** @return iterable<object> */
    public function forVersionGroup(object $media, string $groupUuid): iterable;
}
