<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SearchWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class SearchWorkspaceHandler
{
    public function __construct(
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(SearchWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $query->actor ? $this->resources->model($query->actor, 'actor') : null;
        $this->visibility->forget($workspace, $actor);
        $term = trim($query->query);
        $perPage = $this->pagination->bounded($query->perPage);
        $direction = strtolower($query->sortDir) === 'asc' ? 'asc' : 'desc';

        $folders = Folder::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereNull('deleted_at')
            ->where(fn ($folder) => $folder
                ->where('name', 'like', "%{$term}%")
                ->orWhere('path', 'like', "%{$term}%"));
        $this->visibility->folders($folders, $workspace, $actor);
        $folders->orderBy('name', $direction)->orderBy('id', $direction);

        $sort = in_array($query->sortBy, ['created_at', 'updated_at'], true)
            ? $query->sortBy
            : 'updated_at';
        $media = Media::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereNull('deleted_at')
            ->where(fn ($file) => $file
                ->where('original_name', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('path', 'like', "%{$term}%"));
        $this->visibility->media($media, $workspace, $actor);
        $media->orderBy($sort, $direction)->orderBy('id', $direction);

        $folderPage = $folders->cursorPaginate(
            $perPage,
            ['folders.*'],
            'folder_cursor',
            $this->pagination->cursor($query->folderCursor),
        );
        $filePage = $media->cursorPaginate(
            $perPage,
            ['media.*'],
            'file_cursor',
            $this->pagination->cursor($query->fileCursor),
        );

        return [
            'query' => $term,
            'sort' => ['by' => $sort, 'dir' => $direction],
            'folders' => $this->pagination->mapCursor(
                $folderPage,
                fn (Folder $folder) => $this->projector->folder($folder),
            ),
            'files' => $this->pagination->mapCursor(
                $filePage,
                fn (Media $file) => $this->projector->file($file),
            ),
        ];
    }
}
