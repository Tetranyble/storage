<?php

namespace Tetranyble\Storage\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tetranyble\Storage\Support\StorageConfig;

trait BelongsToStorageWorkspace
{
    public function storageWorkspace(): BelongsTo
    {
        return $this->belongsTo(
            StorageConfig::workspaceModelClass(),
            StorageConfig::actorWorkspaceForeignKey(),
        );
    }

    public function getStorageWorkspace(): ?Model
    {
        $relation = $this->getRelationValue('storageWorkspace');

        return $relation instanceof Model ? $relation : null;
    }

    public function getStorageWorkspaceIdentifier(): int|string|null
    {
        return $this->getAttribute(StorageConfig::actorWorkspaceForeignKey());
    }
}
