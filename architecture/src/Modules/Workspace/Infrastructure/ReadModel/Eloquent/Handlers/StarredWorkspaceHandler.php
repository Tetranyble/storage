<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\ResourceStar;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\StarredWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class StarredWorkspaceHandler
{
    public function __construct(
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(StarredWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $this->resources->model($query->actor, 'actor');
        $this->visibility->forget($workspace, $actor);
        $folderMorph = (new Folder())->getMorphClass();
        $mediaMorph = (new Media())->getMorphClass();

        $folders = Folder::query()
            ->where('folders.workspace_id', $workspace->getKey())
            ->whereExists(fn ($star) => $star
                ->selectRaw('1')
                ->from('resource_stars')
                ->whereColumn('resource_stars.starable_id', 'folders.id')
                ->where('resource_stars.starable_type', $folderMorph)
                ->where('resource_stars.workspace_id', $workspace->getKey())
                ->where('resource_stars.user_id', $actor->getKey()))
            ->orderByDesc($this->latestStarTimestamp('folders.id', $folderMorph, $workspace->getKey(), $actor->getKey()));
        $this->visibility->folders($folders, $workspace, $actor);

        $media = Media::query()
            ->where('media.workspace_id', $workspace->getKey())
            ->whereExists(fn ($star) => $star
                ->selectRaw('1')
                ->from('resource_stars')
                ->whereColumn('resource_stars.starable_id', 'media.id')
                ->where('resource_stars.starable_type', $mediaMorph)
                ->where('resource_stars.workspace_id', $workspace->getKey())
                ->where('resource_stars.user_id', $actor->getKey()))
            ->orderByDesc($this->latestStarTimestamp('media.id', $mediaMorph, $workspace->getKey(), $actor->getKey()));
        $this->visibility->media($media, $workspace, $actor);

        return [
            'folders' => $this->pagination->mapLengthAware(
                $folders->paginate($query->perPage, ['folders.*'], 'folder_page', $query->page),
                fn (Folder $folder) => $this->projector->folder($folder),
            ),
            'files' => $this->pagination->mapLengthAware(
                $media->paginate($query->perPage, ['media.*'], 'file_page', $query->page),
                fn (Media $file) => $this->projector->file($file),
            ),
        ];
    }

    private function latestStarTimestamp(string $resourceIdColumn, string $morph, int $workspaceId, int $userId): Builder
    {
        return ResourceStar::query()
            ->select('created_at')
            ->whereColumn('resource_stars.starable_id', $resourceIdColumn)
            ->where('resource_stars.starable_type', $morph)
            ->where('resource_stars.workspace_id', $workspaceId)
            ->where('resource_stars.user_id', $userId)
            ->latest('created_at')
            ->limit(1);
    }
}
