<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;

/**
 * Eloquent attributes exposed by this package model.
 *
 * @property mixed $attempts
 * @property mixed $disk
 * @property mixed $path
 * @property mixed $reason
 * @property mixed $workspace_id
 */
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
        'next_attempt_at',
        'abandoned_at',
    ];

    protected $casts = [
        'workspace_id' => 'integer',
        'size' => 'integer',
        'attempts' => 'integer',
        'last_attempt_at' => 'datetime',
        'next_attempt_at' => 'datetime',
        'abandoned_at' => 'datetime',
    ];
}
