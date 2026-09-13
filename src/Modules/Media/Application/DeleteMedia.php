<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaDeletion;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\StorageEventPublisher;

class DeleteMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaDeletion $deletion,
        private readonly WorkspaceResourceLocator $resources,
        private readonly StorageEventPublisher $events,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, ?object $actor = null): void
    {
        $media = $this->resources->media($workspace, $media, true);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $deletedId = (int) $this->state->key($media);
        $workspaceValue = $this->state->attribute($media, 'workspace_id');
        $workspaceId = $workspaceValue !== null ? (int) $workspaceValue : null;
        $path = (string) $this->state->attribute($media, 'path', '');

        $this->deletion->delete($media);

        $this->events->mediaPermanentlyDeleted($deletedId, $workspaceId, $path, $actor);
    }
}
