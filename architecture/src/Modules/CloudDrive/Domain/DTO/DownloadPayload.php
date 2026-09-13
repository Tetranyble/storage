<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Domain\DTO;

readonly class DownloadPayload
{
    public function __construct(
        public string $binary,
        public string $filename,
        public string $mime = 'application/octet-stream',
    ) {}
}
