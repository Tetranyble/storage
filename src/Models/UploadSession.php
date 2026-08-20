<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Domain\FileSystem\Enums\UploadSessionStatus;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Models\Concerns\ResolvesConfiguredStorageModels;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        return $this->belongsTo(Folder::class);
    }

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(UploadSessionChunk::class);
    }
}
