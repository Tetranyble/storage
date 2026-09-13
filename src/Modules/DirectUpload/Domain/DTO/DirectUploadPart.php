<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\DTO;

use Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject\PartNumber;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;

final readonly class DirectUploadPart
{
    /** @param array<string, string> $headers */
    public function __construct(
        public PartNumber $partNumber,
        public string $url,
        public array $headers = [],
        public ?FileSize $expectedSize = null,
    ) {}

    public function toArray(): array
    {
        return [
            'part_number' => $this->partNumber->value,
            'url' => $this->url,
            'headers' => $this->headers,
            'expected_size' => $this->expectedSize?->bytes,
        ];
    }
}
