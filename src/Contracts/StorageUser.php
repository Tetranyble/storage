<?php

namespace Tetranyble\Storage\Contracts;

interface StorageUser extends WorkspaceSubject
{
    /**
     * Return the identifier this package should persist for the actor.
     *
     * @return int|string|null
     */
    public function getStorageUserIdentifier(): int|string|null;
}
