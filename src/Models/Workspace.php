<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'storage_used_bytes'  => 'int',
    ];

    public function connectedDrives(): HasMany
    {
        return $this->hasMany(ConnectedDrive::class, StorageConfig::resourceWorkspaceForeignKey());
    }
}
