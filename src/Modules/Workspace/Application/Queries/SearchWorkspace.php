<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class SearchWorkspace
{
    public function __construct(
        public object $workspace,
        public string $query,
        public ?object $actor = null,
        public ?string $folderCursor = null,
        public ?string $fileCursor = null,
        public int $perPage = 50,
        public string $sortBy = 'updated_at',
        public string $sortDir = 'desc',
    ) {}
}
