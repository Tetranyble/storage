<?php

namespace Tetranyble\Storage\Contracts;

use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Models\Media;

interface RemoteMediaImporter
{
    public function uploadFromUrl(
        string $url,
        MediaUploadOptions $options,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
    ): Media;
}
