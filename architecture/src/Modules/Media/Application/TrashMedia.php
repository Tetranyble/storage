<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;

class TrashMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaLibrary $library,
        private readonly ActivityLogger $activities,
        private readonly WorkspaceResourceLocator $resources,
        private readonly StorageEventPublisher $events,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, ?object $actor = null): void
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $this->library->trashMedia($media);
        $this->events->mediaTrashed($media, $actor);
        $this->activities->log(
            $media,
            'storage.media.trashed',
            'object moved to trash.',
            $actor,
            workspaceId: (int) $this->state->key($workspace),
        );
    }
}
