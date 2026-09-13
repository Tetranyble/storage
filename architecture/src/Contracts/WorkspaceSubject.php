<?php

namespace Tetranyble\Storage\Contracts;

interface WorkspaceSubject
{
    /**
     * Return the current workspace model for this actor or host model.
     */
    public function getStorageWorkspace(): ?object;

    /**
     * Return the workspace identifier when only the key is available.
     */
    public function getStorageWorkspaceIdentifier(): int|string|null;
}
