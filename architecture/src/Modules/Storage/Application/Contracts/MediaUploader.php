<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

interface MediaUploader
{
    public function uploadUploadedFile(IncomingFile $file, MediaUploadOptions $options): object;

    public function finalizeChunkedUpload(IncomingFile $file, MediaUploadOptions $options): object;
}
