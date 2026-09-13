<?php

namespace Tetranyble\Storage\Modules\Workspace\Application\Contracts;

use Tetranyble\Storage\Contracts\WorkspaceSubject;

interface StorageUser extends WorkspaceSubject
{
    /**
     * Return the identifier this package should persist for the actor.
     *
     * @return int|string|null
     */
    public function getStorageUserIdentifier(): int|string|null;
}
