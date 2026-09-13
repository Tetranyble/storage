<?php

namespace Tetranyble\Storage\Modules\Access\Application\Contracts;

/** Application port for resolving workspace-owned resources. */
interface WorkspaceResourceLocator
{
    /**
     * @template TResource of object
     *
     * @param  TResource  $media
     * @return TResource
     */
    public function media(object $workspace, object $media, bool $allowTrashed = false): object;

    /**
     * @template TResource of object
     *
     * @param  TResource  $folder
     * @return TResource
     */
    public function folder(object $workspace, object $folder, bool $allowTrashed = false): object;

    public function folderById(object $workspace, ?int $folderId, bool $allowTrashed = false): ?object;
}
