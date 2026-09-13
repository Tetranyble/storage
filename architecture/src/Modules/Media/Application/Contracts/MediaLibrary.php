<?php

namespace Tetranyble\Storage\Modules\Media\Application\Contracts;


/** Command-side media/folder persistence port used by application use cases. */
interface MediaLibrary
{
    public function createWorkspaceRoot(object $workspace): object;
    public function createFolder(object $workspace, string $name, ?object $parent = null): object;
    public function trashMedia(object $media): void;
    public function restoreMedia(object $media): void;
    public function emptyTrash(object $workspace): void;
}
