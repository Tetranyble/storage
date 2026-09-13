<?php

namespace Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models;

use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Eloquent attributes exposed by this package model.
 * @property mixed $created_at
 * @property mixed $description
 * @property mixed $meta
 * @property mixed $subject_id
 * @property mixed $subject_type
 * @property mixed $type
 * @property mixed $user_id
 * @property mixed $uuid
 */
class Activity extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;
    use SoftDeletes;

    protected $table = 'activities';

    protected $fillable = [
        'description',
        'changes',
        'user_id',
        'uuid',
        'workspace_id',
        'type',
        'meta',
        'subject_id',
        'subject_type',
        'subject_uuid',
    ];

    protected $casts = [
        'changes' => 'array',
        'meta' => 'array',
    ];

    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo($this->storageUserModelClass(), 'user_id');
    }
}
