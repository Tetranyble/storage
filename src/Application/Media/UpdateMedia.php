<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Models\Media;

class UpdateMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, array $attributes, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        $allowed = array_intersect_key($attributes, array_flip([
            'description',
            'attribution',
            'custom_properties',
        ]));

        $media->fill($allowed)->save();

        return $media->refresh();
    }
}
