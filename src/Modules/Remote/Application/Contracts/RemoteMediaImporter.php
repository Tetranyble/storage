<?php

namespace Tetranyble\Storage\Modules\Remote\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

interface RemoteMediaImporter
{
    public function uploadFromUrl(
        string $url,
        MediaUploadOptions $options,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
    ): object;
}
