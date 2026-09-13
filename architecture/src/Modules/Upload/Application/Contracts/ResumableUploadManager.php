<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;

interface ResumableUploadManager
{
    public function startSession(UploadSessionOptions $options): object;

    public function appendChunk(
        object $session,
        IncomingFile $chunk,
        int $chunkNumber,
        ?string $checksum = null,
    ): object;

    public function progress(object $session): array;

    public function finalizeSession(object $session): object;

    public function cancelSession(object $session): void;
}
