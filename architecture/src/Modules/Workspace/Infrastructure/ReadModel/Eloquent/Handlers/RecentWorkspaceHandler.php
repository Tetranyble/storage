<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\RecentWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class RecentWorkspaceHandler
{
    public function __construct(
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(RecentWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $this->resources->model($query->actor, 'actor');
        $this->visibility->forget($workspace, $actor);
        $perPage = $this->pagination->bounded($query->perPage);

        if (! (bool) config('tetranyble-storage.activities.enabled', false)) {
            return [
                'folders' => $this->pagination->emptyCursorPage($perPage),
                'files' => $this->pagination->emptyCursorPage($perPage),
            ];
        }

        $folderMorph = (new Folder())->getMorphClass();
        $mediaMorph = (new Media())->getMorphClass();

        $folderActivity = Activity::query()
            ->selectRaw('subject_id, MAX(created_at) as last_activity_at')
            ->where('subject_type', $folderMorph)
            ->where('workspace_id', $workspace->getKey())
            ->where('type', 'like', 'storage.%')
            ->groupBy('subject_id');
        $folders = Folder::query()
            ->joinSub($folderActivity, 'recent_activity', fn ($join) => $join
                ->on('recent_activity.subject_id', '=', 'folders.id'))
            ->select('folders.*', 'recent_activity.last_activity_at as last_activity_at')
            ->where('folders.workspace_id', $workspace->getKey());
        $this->visibility->folders($folders, $workspace, $actor);
        $folders->orderByDesc('last_activity_at')->orderByDesc('id');

        $mediaActivity = Activity::query()
            ->selectRaw('subject_id, MAX(created_at) as last_activity_at')
            ->where('subject_type', $mediaMorph)
            ->where('workspace_id', $workspace->getKey())
            ->where('type', 'like', 'storage.%')
            ->groupBy('subject_id');
        $media = Media::query()
            ->joinSub($mediaActivity, 'recent_activity', fn ($join) => $join
                ->on('recent_activity.subject_id', '=', 'media.id'))
            ->select('media.*', 'recent_activity.last_activity_at as last_activity_at')
            ->where('media.workspace_id', $workspace->getKey());
        $this->visibility->media($media, $workspace, $actor);
        $media->orderByDesc('last_activity_at')->orderByDesc('id');

        $folderPage = $folders->cursorPaginate(
            $perPage,
            ['*'],
            'folder_cursor',
            $this->pagination->cursor($query->folderCursor),
        );
        $filePage = $media->cursorPaginate(
            $perPage,
            ['*'],
            'file_cursor',
            $this->pagination->cursor($query->fileCursor),
        );

        return [
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
