<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Illuminate\Database\Eloquent\Builder;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SharedWithMe;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class SharedWithMeHandler
{
    public function __construct(
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(SharedWithMe $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $this->resources->model($query->actor, 'actor');
        $this->visibility->forget($workspace, $actor);
        $folderMorph = (new Folder())->getMorphClass();
        $mediaMorph = (new Media())->getMorphClass();

        $folders = Folder::query()
            ->where('folders.workspace_id', $workspace->id)
            ->whereExists(fn ($grant) => $grant
                ->selectRaw('1')
                ->from('collaborator_grants')
                ->whereColumn('collaborator_grants.collaboratable_id', 'folders.id')
                ->where('collaborator_grants.collaboratable_type', $folderMorph)
                ->where('collaborator_grants.workspace_id', $workspace->id)
                ->where('collaborator_grants.user_id', $actor->id))
            ->orderByDesc($this->latestGrantTimestamp('folders.id', $folderMorph, $workspace->id, $actor->id));
        $this->visibility->folders($folders, $workspace, $actor);

        $media = Media::query()
            ->where('media.workspace_id', $workspace->id)
            ->whereExists(fn ($grant) => $grant
                ->selectRaw('1')
                ->from('collaborator_grants')
                ->whereColumn('collaborator_grants.collaboratable_id', 'media.id')
                ->where('collaborator_grants.collaboratable_type', $mediaMorph)
                ->where('collaborator_grants.workspace_id', $workspace->id)
                ->where('collaborator_grants.user_id', $actor->id))
            ->orderByDesc($this->latestGrantTimestamp('media.id', $mediaMorph, $workspace->id, $actor->id));
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

    private function latestGrantTimestamp(string $resourceIdColumn, string $morph, int $workspaceId, int $userId): Builder
    {
        return CollaboratorGrant::query()
            ->select('created_at')
            ->whereColumn('collaborator_grants.collaboratable_id', $resourceIdColumn)
            ->where('collaborator_grants.collaboratable_type', $morph)
            ->where('collaborator_grants.workspace_id', $workspaceId)
            ->where('collaborator_grants.user_id', $userId)
            ->latest('created_at')
            ->limit(1);
    }
}
