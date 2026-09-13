<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\DTO;

use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;

readonly class DirectUploadProviderPlan
{
    /**
     * @param  array<string, string>  $headers
     * @param  list<DirectUploadPart>  $parts
     */
    public function __construct(
        public DirectUploadMode $mode,
        public ?string $uploadId = null,
        public ?string $url = null,
        public array $headers = [],
        public array $parts = [],
        public ?int $partSize = null,
        public ?int $totalParts = null,
        public ?int $expectedSize = null,
    ) {}
}
