<?php

namespace Tetranyble\Storage\Modules\Media\Application\Queries;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;

class GetMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
    ) {}

    public function handle(object $workspace, object $media, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeView($workspace, $media, $actor);

        return $media;
    }
}
