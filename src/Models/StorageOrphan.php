<?php

namespace Tetranyble\Storage\Models;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\Concerns\HasUuid;

class StorageOrphan extends Model
{
    use HasUuid;

    protected $table = 'storage_orphans';

    protected $fillable = [
        'workspace_id',
        'disk',
        'path',
        'object_key_hash',
        'size',
        'reason',
        'attempts',
        'last_error',
        'last_attempt_at',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'size' => 'integer',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
    ];
}
