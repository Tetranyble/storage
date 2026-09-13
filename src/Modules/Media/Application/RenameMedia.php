<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRelocation;

class RenameMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRelocation $relocation,
        private readonly WorkspaceResourceLocator $resources,
    ) {}

    public function handle(object $workspace, object $media, string $name, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $relocated = $this->relocation->rename($media, $name, $actor);

        return $relocated;
    }
}
