<?php

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Support\StorageConfig;

class Workspace extends Model
{
    use HasUuid;

    public const DEFAULT_STORAGE_QUOTA_BYTES = 2 * 1024 * 1024 * 1024;

    protected $table = 'workspaces';

    protected $fillable = [
        'uuid',
        'name',
        'storage_quota_bytes',
        'storage_used_bytes',
    ];

    protected $casts = [
        'storage_quota_bytes' => 'int',
        'storage_used_bytes' => 'int',
    ];

    public function connectedDrives(): HasMany
    {
        return $this->hasMany(StorageConfig::connectedDriveModelClass(), StorageConfig::resourceWorkspaceForeignKey());
    }
}
