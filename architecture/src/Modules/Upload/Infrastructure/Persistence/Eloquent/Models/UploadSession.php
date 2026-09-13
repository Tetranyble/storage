<?php

namespace Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\ResolvesConfiguredStorageModels;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Support\StorageConfig;

/**
 * Eloquent attributes exposed by this package model.
 *
 * @property mixed $completed_at
 * @property mixed $conflict_meta
 * @property mixed $conflict_reason
 * @property mixed $disk
 * @property mixed $finalized_at
 * @property mixed $fingerprint
 * @property mixed $identifier
 * @property mixed $media_id
 * @property mixed $mime_type
 * @property mixed $original_name
 * @property mixed $received_bytes
 * @property mixed $received_chunks
 * @property mixed $session_expires_at
 * @property mixed $status
 * @property mixed $total_chunks
 * @property mixed $total_size
 * @property mixed $uuid
 * @property mixed $workspace_id
 */
class UploadSession extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;

    protected $table = 'upload_sessions';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'folder_id',
        'media_id',
        'identifier',
        'active_identifier_hash',
        'fingerprint',
        'original_name',
        'mime_type',
        'disk',
        'status',
        'total_chunks',
        'total_size',
        'chunk_size',
        'received_chunks',
        'received_bytes',
        'upload_options',
        'conflict_reason',
        'conflict_meta',
        'session_expires_at',
        'completed_at',
        'finalized_at',
        'cancelled_at',
        'locked_at',
        'last_chunk_at',
    ];

    protected $casts = [
        'status' => UploadSessionStatus::class,
        'total_chunks' => 'integer',
        'total_size' => 'integer',
        'chunk_size' => 'integer',
        'received_chunks' => 'integer',
        'received_bytes' => 'integer',
        'upload_options' => 'array',
        'conflict_meta' => 'array',
        'session_expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'locked_at' => 'datetime',
        'last_chunk_at' => 'datetime',
    ];

    public function workspace(): BelongsTo
    {
        return $this->belongsTo($this->storageWorkspaceModelClass(), $this->storageWorkspaceForeignKey());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo($this->storageUserModelClass(), 'user_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(StorageConfig::folderModelClass());
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(StorageConfig::mediaModelClass());
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(UploadSessionChunk::class);
    }
}
