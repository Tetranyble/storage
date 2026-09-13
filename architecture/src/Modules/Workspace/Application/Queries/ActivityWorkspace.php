<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class ActivityWorkspace
{
    public function __construct(public object $workspace, public object $actor, public ?string $cursor = null, public int $perPage = 50) {}
}
