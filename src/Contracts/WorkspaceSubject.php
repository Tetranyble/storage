<?php

namespace Tetranyble\Storage\Contracts;

use Illuminate\Database\Eloquent\Model;

interface WorkspaceSubject
{
    /**
     * Return the current workspace model for this actor or host model.
     */
    public function getStorageWorkspace(): ?Model;

    /**
     * Return the workspace identifier when only the key is available.
     *
     * @return int|string|null
     */
    public function getStorageWorkspaceIdentifier(): int|string|null;
}
