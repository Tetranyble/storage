<?php

namespace Tetranyble\Storage\Application\Queries;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Models\Media;

class GetMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeView($workspace, $media, $actor);

        return $media;
    }
}
