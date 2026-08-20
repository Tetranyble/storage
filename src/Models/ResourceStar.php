<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class ResourceStar extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;

    protected $table = 'resource_stars';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'starable_type',
        'starable_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->storageUserModelClass(), 'user_id');
    }

    public function starable(): MorphTo
    {
        return $this->morphTo();
    }
}
