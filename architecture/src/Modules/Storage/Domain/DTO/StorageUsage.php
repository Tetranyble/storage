<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\DTO;

use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;

final readonly class StorageUsage
{
    public function __construct(
        public FileSize $used,
        public FileSize $quota,
    ) {}

    public function remaining(): FileSize
    {
        return $this->quota->minusFloorZero($this->used);
    }

    public function percentage(): float
    {
        if ($this->quota->bytes === 0) {
            return 0.0;
        }

        return ($this->used->bytes / $this->quota->bytes) * 100;
    }

    public function isNearLimit(float $threshold = 0.9): bool
    {
        return $this->quota->bytes > 0
            && $this->used->bytes >= $this->quota->bytes * $threshold;
    }

    public function isOverLimit(): bool
    {
        return $this->used->isGreaterThan($this->quota);
    }
}
