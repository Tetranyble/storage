<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class SharedWithMe
{
    public function __construct(public object $workspace, public object $actor, public int $page = 1, public int $perPage = 50) {}
}
