<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\TrashWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class TrashWorkspaceHandler
{
    public function __construct(
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(TrashWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $direction = strtolower($query->sortDir) === 'asc' ? 'asc' : 'desc';

        $folders = Folder::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderBy($query->sortBy === 'name' ? 'name' : 'deleted_at', $direction)
            ->paginate($query->perPage, ['*'], 'folder_page', $query->page);
        $files = Media::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderBy($query->sortBy === 'name' ? 'original_name' : 'deleted_at', $direction)
            ->paginate($query->perPage, ['*'], 'file_page', $query->page);

        return [
            'folders' => collect($folders->items())
                ->map(fn (Folder $folder) => $this->projector->folder($folder, true))
                ->values(),
            'files' => collect($files->items())
                ->map(fn (Media $file) => $this->projector->file($file, true))
                ->values(),
            'pagination' => [
                'folders' => $this->pagination->lengthAware($folders),
                'files' => $this->pagination->lengthAware($files),
            ],
        ];
    }
}
