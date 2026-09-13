<?php

namespace Tetranyble\Storage\Modules\Media\Application;

use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRelocation;

class MoveMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRelocation $relocation,
        private readonly MediaLibrary $library,
        private readonly WorkspaceResourceLocator $resources,
    ) {}

    public function handle(object $workspace, object $media, ?int $folderId = null, ?object $actor = null): object
    {
        $media = $this->resources->media($workspace, $media);
        $folder = $this->resources->folderById($workspace, $folderId)
            ?? $this->library->createWorkspaceRoot($workspace);

        
        $this->access->authorizeEdit($workspace, $media, $actor);
        $this->access->authorizeEdit($workspace, $folder, $actor);

        $relocated = $this->relocation->move($media, $folder, $actor);
        
        return $relocated;
    }
}
