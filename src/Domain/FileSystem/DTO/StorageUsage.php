<?php

namespace Tetranyble\Storage\Domain\FileSystem\DTO;

class StorageUsage
{
    public function __construct(
        public readonly int $usedBytes,
        public readonly int $quotaBytes,
    ) {}

    public function remainingBytes(): int
    {
        return max(0, $this->quotaBytes - $this->usedBytes);
    }

    public function percentage(): float
    {
        if ($this->quotaBytes === 0) {
            return 0.0;
        }

        return ($this->usedBytes / $this->quotaBytes) * 100;
    }

    public function isNearLimit(float $threshold = 0.9): bool
    {
        return $this->quotaBytes > 0
            && $this->usedBytes >= $this->quotaBytes * $threshold;
    }

    public function isOverLimit(): bool
    {
        return $this->usedBytes > $this->quotaBytes;
    }
}
