<?php

namespace Tetranyble\Storage\Contracts;

use Tetranyble\Storage\Domain\FileSystem\DTO\UploadSessionOptions;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\UploadSession;
use Illuminate\Http\UploadedFile;

interface ResumableUploadManager
{
    public function startSession(UploadSessionOptions $options): UploadSession;

    public function appendChunk(
        UploadSession $session,
        UploadedFile $chunk,
        int $chunkNumber,
        ?string $checksum = null,
    ): UploadSession;

    public function progress(UploadSession $session): array;

    public function finalizeSession(UploadSession $session): Media;

    public function cancelSession(UploadSession $session): void;
}
