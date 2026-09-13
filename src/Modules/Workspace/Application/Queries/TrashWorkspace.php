<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class TrashWorkspace
{
    public function __construct(
        public object $workspace,
        public string $sortBy = 'deleted_at',
        public string $sortDir = 'desc',
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
