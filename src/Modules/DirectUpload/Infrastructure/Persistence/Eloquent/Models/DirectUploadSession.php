<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Support\StorageConfig;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\ResolvesConfiguredStorageModels;

class DirectUploadSession extends Model
{
    use HasUuid;
    use ResolvesConfiguredStorageModels;

    protected $table = 'direct_upload_sessions';

    protected $fillable = [
        'uuid',
        'workspace_id',
        'user_id',
        'folder_id',
        'media_id',
        'provider',
        'mode',
        'status',
        'disk',
        'object_key',
        'object_key_hash',
        'original_name',
        'mime_type',
        'expected_size',
        'expected_sha256',
        'provider_upload_id',
        'part_size',
        'total_parts',
        'reserved_bytes',
        'upload_options',
        'session_expires_at',
        'finalizing_at',
        'finalized_at',
        'cancelled_at',
        'failed_at',
        'failure_reason',
        'cleanup_pending',
        'cleanup_attempts',
        'cleanup_error',
        'cleanup_attempted_at',
    ];

    protected $casts = [
        'mode' => DirectUploadMode::class,
        'status' => DirectUploadStatus::class,
        'disk' => Disk::class,
        'expected_size' => 'integer',
        'part_size' => 'integer',
        'total_parts' => 'integer',
        'reserved_bytes' => 'integer',
        'upload_options' => 'array',
        'session_expires_at' => 'datetime',
        'finalizing_at' => 'datetime',
        'finalized_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'failed_at' => 'datetime',
        'cleanup_pending' => 'boolean',
        'cleanup_attempts' => 'integer',
        'cleanup_attempted_at' => 'datetime',
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
}
