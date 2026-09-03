<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Events\MediaTrashed;
use Tetranyble\Storage\Models\Media;

class TrashMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaLibraryService $library,
        private readonly ActivityLogger $activities,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $this->library->trashMedia($media);
        Event::dispatch(new MediaTrashed($media, $actor));
        $this->activities->log(
            $media,
            'storage.media.trashed',
            'Media moved to trash.',
            $actor,
            workspaceId: (int) $workspace->getKey(),
        );
    }
}
