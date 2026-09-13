<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Shared\Infrastructure\Persistence\Eloquent\Models\Concerns\HasUuid;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

/**
 * Eloquent attributes exposed by this package model.
 *
 * @property mixed $disk
 * @property mixed $format
 * @property mixed $height
 * @property mixed $is_primary
 * @property mixed $kind
 * @property mixed $media_id
 * @property mixed $mime_type
 * @property mixed $path
 * @property mixed $size
 * @property mixed $uuid
 * @property mixed $variant
 * @property mixed $width
 */
class MediaDerivative extends Model
{
    use HasUuid;

    protected $table = 'media_derivatives';

    protected $fillable = [
        'uuid',
        'media_id',
        'workspace_id',
        'kind',
        'variant',
        'format',
        'mime_type',
        'disk',
        'path',
        'size',
        'width',
        'height',
        'sha256',
        'is_primary',
        'generated_at',
        'metadata',
    ];

    protected $casts = [
        'kind' => MediaDerivativeKind::class,
        'disk' => Disk::class,
        'size' => 'integer',
        'width' => 'integer',
        'height' => 'integer',
        'is_primary' => 'boolean',
        'generated_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class);
    }
}
