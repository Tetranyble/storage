<?php

namespace Tetranyble\Storage\Models;

use Tetranyble\Storage\Domain\Media\DTO\MediaMailPayload;
use Tetranyble\Storage\Domain\Media\MediaMailService;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Models\Comment;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Models\Concerns\HasUuid;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Contracts\Mail\Attachable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Mail\Attachment as MailAttachment;

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
        'custom_properties',
        'access_scope',
        'is_temporary',
        'temporary_expires_at',
        'thumbnail_path',
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
        'custom_properties' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $media): void {
            $media->hydrateImageDimensions();
        });
    }

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
        if (! $this->thumbnail_path) {
            return null;
        }

        return $this->fileSystem()->url($this->thumbnail_path, $this->disk);
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
            'mediable_type' => get_class($model),
        ])->save();

        return $this;
    }

    public function toMailAttachment(): MailAttachment
    {
        return app(MediaMailService::class)->attachment($this);
    }

    public function toMailDataAttachment(?int $maxSizeBytes = null): MailAttachment
    {
        return app(MediaMailService::class)->dataAttachment($this, $maxSizeBytes);
    }

    public function toMailBase64Payload(?int $maxSizeBytes = null): MediaMailPayload
    {
        return app(MediaMailService::class)->base64Payload($this, $maxSizeBytes);
    }

    public function toSignedEmailLinkPayload(int $ttlMinutes = 60): MediaMailPayload
    {
        return app(MediaMailService::class)->signedLinkPayload($this, $ttlMinutes);
    }

    public function asEmailAttachment(bool $asBase64 = false, int $ttlMinutes = 60, ?int $maxSizeBytes = null): array
    {
        if ($asBase64) {
            return $this->toMailBase64Payload($maxSizeBytes)->toArray();
        }

        return $this->toSignedEmailLinkPayload($ttlMinutes)->toArray();
    }

    protected function hydrateImageDimensions(): void
    {
        if (! is_string($this->path) || trim($this->path) === '' || $this->isExternalPath($this->path)) {
            return;
        }

        if ($this->exists && ! $this->isDirty(['path', 'disk', 'mime_type']) && $this->width !== null && $this->height !== null) {
            return;
        }

        $disk = $this->disk instanceof Disk ? $this->disk : null;
        if (! $disk) {
            return;
        }

        try {
            $mime = $this->mime_type ?: $this->fileSystem()->mimeType($this->path, $disk);
            if (! is_string($mime) || ! str_starts_with(strtolower($mime), 'image/')) {
                if ($this->isDirty(['path', 'mime_type'])) {
                    $this->width = null;
                    $this->height = null;
                }

                return;
            }

            $binary = $this->fileSystem()->get(
                $this->path,
                $disk,
                (int) config('tetranyble-storage.image_metadata.max_bytes', 15 * 1024 * 1024)
            );

            $dimensions = @getimagesizefromstring($binary);
            if (! is_array($dimensions) || ! isset($dimensions[0], $dimensions[1])) {
                return;
            }

            $this->width = (int) $dimensions[0];
            $this->height = (int) $dimensions[1];

            if (! $this->mime_type && isset($dimensions['mime']) && is_string($dimensions['mime'])) {
                $this->mime_type = $dimensions['mime'];
            }
        } catch (\Throwable) {
        }
    }

    private function isExternalPath(string $path): bool
    {
        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

}
