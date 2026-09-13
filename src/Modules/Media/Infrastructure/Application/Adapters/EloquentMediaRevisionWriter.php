<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRevisionWriter;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;

final class EloquentMediaRevisionWriter implements MediaRevisionWriter
{
    public function __construct(private readonly MediaService $media) {}

    public function createRevisionFromUpload(object $media, IncomingFile $file, ?int $userId = null): object
    {
        $uploaded = new UploadedFile(
            $file->localPath,
            $file->originalName,
            $file->clientMimeType ?? $file->detectedMimeType,
            null,
            true,
        );

        return $this->media->createRevisionFromUpload($this->typed($media), $uploaded, $userId);
    }

    public function restoreRevision(object $revision, ?int $userId = null): object
    {
        return $this->media->restoreRevision($this->typed($revision), $userId);
    }

    private function typed(object $media): Media
    {
        if (! $media instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

        return $media;
    }
}
