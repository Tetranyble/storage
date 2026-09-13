<?php

namespace Tetranyble\Storage\Modules\Upload\Application\DTO;

use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

readonly class UploadSessionOptions
{
    public function __construct(
        public string $identifier,
        public MediaUploadOptions $upload,
        public int $totalChunks,
        public ?int $totalSize = null,
        public ?int $chunkSize = null,
        public ?string $mimeType = null,
        public ?\DateTimeInterface $expiresAt = null,
    ) {}

    public function originalName(): string
    {
        $originalName = trim((string) ($this->upload->originalName ?? ''));

        return $originalName !== '' ? $originalName : 'upload.bin';
    }
}
