<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Application\DTO;

use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadMode;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadProviderPlan;

readonly class DirectUploadStartResult
{
    public function __construct(
        public DirectUploadMode $mode,
        public ?object $session = null,
        public ?DirectUploadProviderPlan $plan = null,
        public ?string $fallbackReason = null,
    ) {}

    public function isFallback(): bool
    {
        return $this->mode === DirectUploadMode::FALLBACK;
    }
}
