<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Models\Media;

class RestoreMediaRevision
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaService $mediaService,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media, true);
        $this->access->authorizeEdit($workspace, $media, $actor);

        return $this->mediaService->restoreRevision(
            $media,
            $actor ? (int) $actor->getKey() : null,
        );
    }
}
