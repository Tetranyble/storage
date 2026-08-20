<?php

namespace Tetranyble\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Contracts\WorkspaceSubject;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable implements WorkspaceSubject
{
    use HasUuid;

    protected $table = 'users';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'name',
        'email',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(StorageConfig::workspaceModelClass(), StorageConfig::actorWorkspaceForeignKey());
    }

    public function getStorageWorkspace(): ?Model
    {
        $workspace = $this->getRelationValue('workspace');

        return $workspace instanceof Model ? $workspace : null;
    }

    public function getStorageWorkspaceIdentifier(): int|string|null
    {
        return $this->getAttribute(StorageConfig::actorWorkspaceForeignKey());
    }
}
