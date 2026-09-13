<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models;

use Tetranyble\Storage\Modules\Media\Application\DTO\MediaMailPayload;
use Tetranyble\Storage\Http\Mail\LaravelMediaMailService;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Models\CollaboratorGrant;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Comment;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaStatus;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Mail\Attachment as MailAttachment;

/**
 * Eloquent attributes exposed by this package model.
 * @property mixed $access_scope
 * @property mixed $archived_at
 * @property mixed $attribution
 * @property mixed $created_at
 * @property mixed $current
 * @property mixed $deleted_at
 * @property mixed $description
 * @property mixed $disk
 * @property mixed $folder_id
 * @property mixed $is_temporary
 * @property mixed $mediable_id
 * @property mixed $mediable_type
 * @property mixed $mime_type
 * @property mixed $module
 * @property mixed $original_name
 * @property mixed $path
 * @property mixed $previous_version_id
 * @property mixed $processing_attempts
 * @property mixed $processing_available_at
 * @property mixed $processing_dispatch_attempts
 * @property mixed $processing_dispatched_at
 * @property mixed $processing_started_at
 * @property mixed $processing_status
 * @property mixed $quarantine_reason
 * @property mixed $quarantined_at
 * @property mixed $scan_completed_at
 * @property mixed $sha256
 * @property mixed $size
 * @property mixed $updated_at
 * @property mixed $uploaded_at
 * @property mixed $uploaded_by
 * @property mixed $use
 * @property mixed $uuid
 * @property mixed $version_group_uuid
 * @property mixed $version_number
 * @property mixed $virus_scan_status
 * @property mixed $workspace_id
 */
class Media extends Model implements Attachable
{
    use HasUuid;
    use SoftDeletes;

    protected $table = 'media';

    protected $fillable = [
        'description',
        'attribution',
        'size',
        'path',
        'original_name',
        'mime_type',
        'width',
        'height',
        'disk',
        'use',
        'module',
        'upload_strategy',
        'uuid',
        'sha256',
        'processed_rows',
        'total_rows',
        'completed_at',
        'inserted_items',
        'skipped_items',
        'status',
        'error',
        'workspace_id',
        'archived_at',
        'folder_id',
        'uploaded_by',
        'uploaded_at',
        'virus_scan_status',
        'detected_mime_type',
        'processing_status',
        'processing_attempts',
        'processing_dispatch_attempts',
        'processing_started_at',
        'processing_dispatched_at',
        'processing_available_at',
        'processing_completed_at',
        'processing_error',
        'scan_started_at',
        'scan_completed_at',
        'scan_engine',
        'scan_signature',
        'quarantined_at',
        'quarantine_reason',
        'custom_properties',
        'direct_upload_session_uuid',
        'access_scope',
        'is_temporary',
        'temporary_expires_at',
    ];

    protected $casts = [
        'current' => 'boolean',
        'version_number' => 'integer',
        'disk' => Disk::class,
        'use' => MediaPurpose::class,
        'upload_strategy' => UploadStrategy::class,
        'archived_at' => 'datetime',
        'is_temporary' => 'boolean',
        'temporary_expires_at' => 'datetime',
        'completed_at' => 'datetime',
        'status' => MediaStatus::class,
        'uploaded_at' => 'datetime',
        'access_scope' => AccessScope::class,
        'width' => 'integer',
        'height' => 'integer',
        'size' => 'integer',
        'custom_properties' => 'array',
        'virus_scan_status' => VirusScanStatus::class,
        'processing_status' => MediaProcessingStatus::class,
        'processing_attempts' => 'integer',
        'processing_dispatch_attempts' => 'integer',
        'processing_started_at' => 'datetime',
        'processing_dispatched_at' => 'datetime',
        'processing_available_at' => 'datetime',
        'processing_completed_at' => 'datetime',
        'scan_started_at' => 'datetime',
        'scan_completed_at' => 'datetime',
        'quarantined_at' => 'datetime',
    ];

    public function scopeTemporary($query)
    {
        return $query->where('is_temporary', true);
    }

    public function mediable(): MorphTo
    {
        return $this->morphTo();
    }

    protected function fileSystem(): FileSystemContract
    {
        return app(FileSystemContract::class);
    }

    public function getUrlAttribute(): string
    {
        return $this->fileSystem()->url($this->path, $this->disk);
    }

    public function getThumbnailUrlAttribute(): ?string
    {
        $thumbnail = $this->derivatives()
            ->where('kind', \Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind::THUMBNAIL->value)
            ->where('is_primary', true)
            ->orderByDesc('id')
            ->first();

        if (! $thumbnail || ! $thumbnail->getAttribute('disk') instanceof Disk) {
            return null;
        }

        return $this->fileSystem()->url($thumbnail->getAttribute('path'), $thumbnail->disk);
    }

    public function getFullPathAttribute(): string
    {
        return $this->fileSystem()->path($this->path, $this->disk);
    }

    public function getSignedUrlAttribute(): string
    {
        return $this->fileSystem()->signedUrl($this->path, $this->disk, 60);
    }

    public function getPublicUrlAttribute(): string
    {
        return $this->fileSystem()->publicUrl($this->path, $this->disk);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'folder_id');
    }

    public function previousVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_version_id');
    }

    public function nextVersions()
    {
        return $this->hasMany(self::class, 'previous_version_id');
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

    public function derivatives(): HasMany
    {
        return $this->hasMany(MediaDerivative::class)->orderBy('id');
    }

    public function getIsArchivedAttribute(): bool
    {
        return $this->archived_at !== null;
    }

    public function scopeCurrent($query)
    {
        return $query->where('current', true);
    }

    public function associate(Model $model): self
    {
        $this->forceFill([
            'workspace_id' => StorageConfig::actorWorkspaceId($model),
            'current' => true,
            'mediable_id' => $model->getKey(),
            'mediable_type' => $model->getMorphClass(),
        ])->save();

        return $this;
    }

    public function toMailAttachment(): MailAttachment
    {
        return app(LaravelMediaMailService::class)->attachment($this);
    }

    public function toMailDataAttachment(?int $maxSizeBytes = null): MailAttachment
    {
        return app(LaravelMediaMailService::class)->dataAttachment($this, $maxSizeBytes);
    }

    public function toMailBase64Payload(?int $maxSizeBytes = null): MediaMailPayload
    {
        return app(LaravelMediaMailService::class)->base64Payload($this, $maxSizeBytes);
    }

    public function toSignedEmailLinkPayload(int $ttlMinutes = 60): MediaMailPayload
    {
        return app(LaravelMediaMailService::class)->signedLinkPayload($this, $ttlMinutes);
    }

    public function asEmailAttachment(bool $asBase64 = false, int $ttlMinutes = 60, ?int $maxSizeBytes = null): array
    {
        if ($asBase64) {
            return $this->toMailBase64Payload($maxSizeBytes)->toArray();
        }

        return $this->toSignedEmailLinkPayload($ttlMinutes)->toArray();
    }


}
