<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\ActivityWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class ActivityWorkspaceHandler
{
    public function __construct(
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(ActivityWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $this->resources->model($query->actor, 'actor');
        $this->visibility->forget($workspace, $actor);
        $perPage = $this->pagination->bounded($query->perPage);

        if (! (bool) config('tetranyble-storage.activities.enabled', false)) {
            return [
                'activities' => collect(),
                'pagination' => $this->pagination->emptyCursorPage($perPage)['pagination'],
            ];
        }

        $mediaMorph = (new Media)->getMorphClass();
        $folderMorph = (new Folder)->getMorphClass();
        $visibleMedia = $this->visibility->visibleMedia($workspace, $actor)->select('media.id');
        $visibleFolders = $this->visibility->visibleFolders($workspace, $actor)->select('folders.id');

        $activities = Activity::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('type', 'like', 'storage.%')
            ->where(function ($subject) use ($mediaMorph, $folderMorph, $visibleMedia, $visibleFolders): void {
                $subject->where(fn ($media) => $media
                    ->where('subject_type', $mediaMorph)
                    ->whereIn('subject_id', $visibleMedia))
                    ->orWhere(fn ($folder) => $folder
                        ->where('subject_type', $folderMorph)
                        ->whereIn('subject_id', $visibleFolders));
            })
            ->with('subject')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->cursorPaginate(
                $perPage,
                ['*'],
                'cursor',
                $this->pagination->cursor($query->cursor),
            );

        return [
            'activities' => collect($activities->items())
                ->map(fn (Activity $activity) => $this->projector->activity($activity))
                ->values(),
            'pagination' => $this->pagination->cursorMeta($activities),
        ];
    }
}
