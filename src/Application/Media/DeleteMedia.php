<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\MediaDeletionService;
use Tetranyble\Storage\Events\MediaPermanentlyDeleted;
use Tetranyble\Storage\Models\Media;

class DeleteMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaDeletionService $deletion,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->resources->media($workspace, $media, true);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $deletedId = (int) $media->getKey();
        $workspaceId = $media->workspace_id ? (int) $media->workspace_id : null;
        $path = $media->path;

        $this->deletion->delete($media);

        Event::dispatch(new MediaPermanentlyDeleted($deletedId, $workspaceId, $path, $actor));
    }
}
