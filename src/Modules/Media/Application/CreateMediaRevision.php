<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRevisionWriter;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;

class CreateMediaRevision
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRevisionWriter $mediaService,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, IncomingFile $file, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $revision = $this->mediaService->createRevisionFromUpload(
            $media,
            $file,
            $actor ? (int) $this->state->key($actor) : null,
        );

        return $revision;
    }
}
