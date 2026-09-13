<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class BrowseWorkspace
{
    public function __construct(
        public object $workspace,
        public string $relativePath = '',
        public string $search = '',
        public ?object $actor = null,
        public string $sortBy = 'name',
        public string $sortDir = 'asc',
        public int $page = 1,
        public int $perPage = 50,
    ) {}
}
