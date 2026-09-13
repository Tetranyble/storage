<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries;

use Tetranyble\Storage\Modules\Workspace\Application\Contracts\WorkspaceReadModel;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\ActivityWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\BrowseWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\MediaVersions;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\RecentWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SearchWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SharedWithMe;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\StarredWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\TrashWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\ActivityWorkspaceHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\BrowseWorkspaceHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\MediaVersionsHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\RecentWorkspaceHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\SearchWorkspaceHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\SharedWithMeHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\StarredWorkspaceHandler;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers\TrashWorkspaceHandler;

/**
 * Eloquent CQRS read adapter plus a compatibility surface for the pre-Step-8 API.
 * New consumers should depend on WorkspaceReadModel and pass explicit query objects.
 */
final class WorkspaceFileQueryService implements WorkspaceReadModel
{
    public function __construct(
        private readonly BrowseWorkspaceHandler $browseHandler,
        private readonly TrashWorkspaceHandler $trashHandler,
        private readonly StarredWorkspaceHandler $starredHandler,
        private readonly SharedWithMeHandler $sharedHandler,
        private readonly MediaVersionsHandler $versionsHandler,
        private readonly SearchWorkspaceHandler $searchHandler,
        private readonly RecentWorkspaceHandler $recentHandler,
        private readonly ActivityWorkspaceHandler $activityHandler,
    ) {}

    public function browse(BrowseWorkspace $query): array
    {
        return $this->browseHandler->handle($query);
    }

    public function trash(TrashWorkspace $query): array
    {
        return $this->trashHandler->handle($query);
    }

    public function starred(StarredWorkspace $query): array
    {
        return $this->starredHandler->handle($query);
    }

    public function sharedWithMe(SharedWithMe $query): array
    {
        return $this->sharedHandler->handle($query);
    }

    public function mediaVersions(MediaVersions $query): array
    {
        return $this->versionsHandler->handle($query);
    }

    public function search(SearchWorkspace $query): array
    {
        return $this->searchHandler->handle($query);
    }

    public function recent(RecentWorkspace $query): array
    {
        return $this->recentHandler->handle($query);
    }

    public function activity(ActivityWorkspace $query): array
    {
        return $this->activityHandler->handle($query);
    }

    public function indexPayload(object $workspace, string $relativePath = '', string $search = '', ?object $actor = null, string $sortBy = 'name', string $sortDir = 'asc', int $page = 1, int $perPage = 50): array
    {
        return $this->browse(new BrowseWorkspace($workspace, $relativePath, $search, $actor, $sortBy, $sortDir, $page, $perPage));
    }

    public function trashPayload(object $workspace, string $sortBy = 'deleted_at', string $sortDir = 'desc', int $page = 1, int $perPage = 50): array
    {
        return $this->trash(new TrashWorkspace($workspace, $sortBy, $sortDir, $page, $perPage));
    }

    public function starredPayload(object $workspace, object $actor, int $page = 1, int $perPage = 50): array
    {
        return $this->starred(new StarredWorkspace($workspace, $actor, $page, $perPage));
    }

    public function sharedWithMePayload(object $workspace, object $actor, int $page = 1, int $perPage = 50): array
    {
        return $this->sharedWithMe(new SharedWithMe($workspace, $actor, $page, $perPage));
    }

    public function mediaVersionsPayload(object $workspace, object $media, ?object $actor = null): array
    {
        return $this->mediaVersions(new MediaVersions($workspace, $media, $actor));
    }

    public function searchCursorPayload(object $workspace, string $query, ?object $actor = null, ?string $folderCursor = null, ?string $fileCursor = null, int $perPage = 50, string $sortBy = 'updated_at', string $sortDir = 'desc'): array
    {
        return $this->search(new SearchWorkspace($workspace, $query, $actor, $folderCursor, $fileCursor, $perPage, $sortBy, $sortDir));
    }

    public function recentCursorPayload(object $workspace, object $actor, ?string $folderCursor = null, ?string $fileCursor = null, int $perPage = 25): array
    {
        return $this->recent(new RecentWorkspace($workspace, $actor, $folderCursor, $fileCursor, $perPage));
    }

    public function activityCursorPayload(object $workspace, object $actor, ?string $cursor = null, int $perPage = 50): array
    {
        return $this->activity(new ActivityWorkspace($workspace, $actor, $cursor, $perPage));
    }
}
