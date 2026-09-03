<?php

namespace Tetranyble\Storage\Application\Media;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Application\Support\WorkspaceResourceGuard;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\MediaRelocationService;
use Tetranyble\Storage\Models\Media;

class MoveMedia
{
    public function __construct(
        private readonly ResourceAccessControl $access,
        private readonly MediaRelocationService $relocation,
        private readonly MediaLibraryService $library,
        private readonly WorkspaceResourceGuard $resources,
    ) {}

    public function handle(Model $workspace, Media $media, ?int $folderId = null, ?Model $actor = null): Media
    {
        $media = $this->resources->media($workspace, $media);
        $folder = $this->resources->folderById($workspace, $folderId)
            ?? $this->library->createWorkspaceRoot($workspace);

        $this->access->authorizeEdit($workspace, $media, $actor);
        $this->access->authorizeEdit($workspace, $folder, $actor);

        return $this->relocation->move($media, $folder, $actor);
    }
}
