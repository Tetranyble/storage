<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;

class UpdateMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceLocator $resources,
        private readonly ResourceState $state,
    ) {}

    public function handle(object $workspace, object $media, array $attributes, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $allowed = array_intersect_key($attributes, array_flip([
            'description',
            'attribution',
            'custom_properties',
        ]));

        return $this->state->update($media, $allowed);
    }
}
