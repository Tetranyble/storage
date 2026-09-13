<?php

namespace Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models;

use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Comment;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\ResourceStar;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

/**
 * Eloquent attributes exposed by this package model.
 * @property mixed $access_scope
 * @property mixed $archived_at
 * @property mixed $created_at
 * @property mixed $created_by
 * @property mixed $deleted_at
 * @property mixed $is_root
 * @property mixed $name
 * @property mixed $parent_id
 * @property mixed $path
 * @property mixed $slug
 * @property mixed $updated_at
 * @property mixed $uuid
 * @property mixed $workspace_id
 */
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
