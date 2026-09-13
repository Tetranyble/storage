<?php

namespace Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\ResolvesConfiguredStorageModels;

/**
 * Eloquent attributes exposed by this package model.
 *
 * @property mixed $access_level
 * @property mixed $downloads_count
 * @property mixed $expires_at
 * @property mixed $max_downloads
 * @property mixed $password_hash
 * @property mixed $requires_password
 * @property mixed $token
 * @property mixed $workspace_id
 */
class MediaShare extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;

    protected $table = 'media_shares';

    protected $fillable = [
        'workspace_id',
        'shareable_type',
        'shareable_id',
        'token',
        'access_level',
        'expires_at',
        'max_downloads',
        'downloads_count',
        'requires_password',
        'password_hash',
        'created_by',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'requires_password' => 'bool',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo($this->storageWorkspaceModelClass(), $this->storageWorkspaceForeignKey());
    }

    public function shareable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->greaterThan($this->expires_at);
    }

    public function hasReachedDownloadsLimit(): bool
    {
        return $this->max_downloads !== null && $this->downloads_count >= $this->max_downloads;
    }
}
