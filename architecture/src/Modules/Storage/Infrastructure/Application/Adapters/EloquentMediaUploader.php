<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Infrastructure\Application\Adapters;

use Illuminate\Http\UploadedFile;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\MediaUploader;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

final class EloquentMediaUploader implements MediaUploader
{
    public function __construct(private readonly MediaService $media) {}

    public function uploadUploadedFile(IncomingFile $file, MediaUploadOptions $options): object
    {
        return $this->media->uploadUploadedFile($this->uploaded($file), $options);
    }

    public function finalizeChunkedUpload(IncomingFile $file, MediaUploadOptions $options): object
    {
        return $this->media->finalizeChunkedUpload($this->uploaded($file), $options);
    }

    private function uploaded(IncomingFile $file): UploadedFile
    {
        return new UploadedFile(
            $file->localPath,
            $file->originalName,
            $file->clientMimeType ?? $file->detectedMimeType,
            null,
            true,
        );
    }
}
