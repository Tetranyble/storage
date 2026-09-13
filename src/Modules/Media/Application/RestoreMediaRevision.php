<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRevisionWriter;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;

class RestoreMediaRevision
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRevisionWriter $mediaService,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media, true);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $revision = $this->mediaService->restoreRevision(
            $media,
            $actor ? (int) $this->state->key($actor) : null,
        );

        return $revision;
    }
}
