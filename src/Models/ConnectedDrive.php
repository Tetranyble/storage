<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ConnectedDrive extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;
    use SoftDeletes;

    protected $table = 'connected_drives';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'provider',
        'name',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'credentials',
        'status',
        'is_default',
        'default_slot',
        'access_scope',
        'last_error',
        'connected_at',
    ];

    protected $casts = [
        'provider'         => CloudProvider::class,
        'status'           => ConnectedDriveStatus::class,
        'token_expires_at' => 'datetime',
        'connected_at'     => 'datetime',
        'is_default'       => 'boolean',
        'access_scope'     => AccessScope::class,
        'access_token'     => 'encrypted',
        'refresh_token'    => 'encrypted',
        'credentials'      => 'encrypted:array',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
        'credentials',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $drive): void {
            $drive->default_slot = $drive->is_default ? 'default' : null;
        });
        static::deleting(function (self $drive): void {
            if (! $drive->isForceDeleting() && $drive->is_default) {
                $drive->forceFill(['is_default' => false, 'default_slot' => null])->saveQuietly();
            }
        });
    }

    public function setIsDefaultAttribute(bool|int|string $value): void
    {
        $isDefault = filter_var($value, FILTER_VALIDATE_BOOL);
        $this->attributes['is_default'] = $isDefault;
        $this->attributes['default_slot'] = $isDefault ? 'default' : null;
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo($this->storageWorkspaceModelClass(), $this->storageWorkspaceForeignKey());
    }

    public function isTokenExpired(): bool
    {
        if ($this->token_expires_at === null) {
            return false;
        }

        return $this->token_expires_at->isPast();
    }

    public function isTokenExpiringSoon(int $bufferSeconds = 120): bool
    {
        if ($this->token_expires_at === null) {
            return false;
        }

        return $this->token_expires_at->diffInSeconds(now(), false) > -$bufferSeconds;
    }

    public function markError(string $message): void
    {
        $this->forceFill([
            'status'     => ConnectedDriveStatus::ERROR,
            'last_error' => $message,
        ])->save();
    }

    public function markConnected(): void
    {
        $this->forceFill([
            'status'       => ConnectedDriveStatus::CONNECTED,
            'last_error'   => null,
            'connected_at' => now(),
        ])->save();
    }
}
