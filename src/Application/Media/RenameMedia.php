<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\MediaRelocationService;
use Tetranyble\Storage\Models\Media;

class RenameMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRelocationService $relocation,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, string $name, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media);
        $this->access->authorizeEdit($workspace, $media, $actor);

        return $this->relocation->rename($media, $name, $actor);
    }
}
