<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Models\Comment;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tetranyble\Storage\Models\MediaShare;

class Folder extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;
    use SoftDeletes;

    protected $table = 'folders';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'parent_id',
        'created_by',
        'name',
        'slug',
        'path',
        'access_scope',
        'is_root',
        'archived_at',
    ];

    protected $casts = [
        'is_root' => 'bool',
        'access_scope' => AccessScope::class,
        'archived_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo($this->storageWorkspaceModelClass(), $this->storageWorkspaceForeignKey());
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function media(): HasMany
    {
        return $this->hasMany(Media::class, 'folder_id');
    }

    public function shares(): MorphMany
    {
        return $this->morphMany(MediaShare::class, 'shareable');
    }

    public function collaborators(): MorphMany
    {
        return $this->morphMany(CollaboratorGrant::class, 'collaboratable');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'subject')->latest();
    }

    public function stars(): MorphMany
    {
        return $this->morphMany(ResourceStar::class, 'starable');
    }

    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable')->whereNull('parent_id')->orderBy('created_at');
    }

    public function scopeRoot($query)
    {
        return $query->where('is_root', true);
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }
}
