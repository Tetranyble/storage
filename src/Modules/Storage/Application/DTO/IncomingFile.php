<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Application\DTO;

use InvalidArgumentException;

/**
 * Framework-neutral description of a local incoming file.
 *
 * HTTP adapters translate Laravel UploadedFile instances into this DTO before
 * invoking application use cases. Infrastructure may safely open localPath.
 */
readonly class IncomingFile
{
    public function __construct(
        public string $localPath,
        public string $originalName,
        public int $size,
        public ?string $clientMimeType = null,
        public ?string $detectedMimeType = null,
    ) {
        if ($this->localPath === '') {
            throw new InvalidArgumentException('Incoming file path cannot be empty.');
        }
        if ($this->originalName === '') {
            throw new InvalidArgumentException('Incoming file original name cannot be empty.');
        }
        if ($this->size < 0) {
            throw new InvalidArgumentException('Incoming file size cannot be negative.');
        }
    }

    public function mimeType(): ?string
    {
        return $this->clientMimeType ?: $this->detectedMimeType;
    }
}
