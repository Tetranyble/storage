<?php

namespace Tetranyble\Storage\Models\Concerns;

use Tetranyble\Storage\Support\StorageConfig;

trait ResolvesConfiguredStorageModels
{
    protected function storageWorkspaceModelClass(): string
    {
        return StorageConfig::workspaceModelClass();
    }

    protected function storageUserModelClass(): string
    {
        return StorageConfig::userModelClass();
    }

    protected function storageWorkspaceForeignKey(): string
    {
        return StorageConfig::resourceWorkspaceForeignKey();
    }
}
