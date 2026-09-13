<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application\DTO;

use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

readonly class DirectUploadRequest
{
    public function __construct(
        public MediaUploadOptions $upload,
        public int $expectedSize,
        public ?string $mimeType = null,
        public ?string $sha256 = null,
        public ?int $partSize = null,
        public ?\DateTimeInterface $expiresAt = null,
    ) {}
}
