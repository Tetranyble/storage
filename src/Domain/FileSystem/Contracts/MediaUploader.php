<?php

namespace Tetranyble\Storage\Domain\FileSystem\Contracts;

use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Models\Media;
use Illuminate\Http\UploadedFile;

interface MediaUploader
{
    public function uploadUploadedFile(UploadedFile $file, MediaUploadOptions $options): Media;

    public function finalizeChunkedUpload(UploadedFile $file, MediaUploadOptions $options): Media;
}
