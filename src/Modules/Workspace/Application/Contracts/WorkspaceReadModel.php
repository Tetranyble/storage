<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Contracts;

use Tetranyble\Storage\Modules\Workspace\Application\Queries\ActivityWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\BrowseWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\MediaVersions;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\RecentWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SearchWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SharedWithMe;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\StarredWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\TrashWorkspace;

/** CQRS read port. Implementations may use persistence-optimized projections directly. */
interface WorkspaceReadModel
{
    public function browse(BrowseWorkspace $query): array;
    public function trash(TrashWorkspace $query): array;
    public function starred(StarredWorkspace $query): array;
    public function sharedWithMe(SharedWithMe $query): array;
    public function mediaVersions(MediaVersions $query): array;
    public function search(SearchWorkspace $query): array;
    public function recent(RecentWorkspace $query): array;
    public function activity(ActivityWorkspace $query): array;
}
