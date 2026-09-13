<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class RecentWorkspace
{
    public function __construct(
        public object $workspace,
        public object $actor,
        public ?string $folderCursor = null,
        public ?string $fileCursor = null,
        public int $perPage = 25,
    ) {}
}
