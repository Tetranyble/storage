<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Versioning\Domain\Aggregates;

use RuntimeException;

/** Domain rules for media revision groups, independent of persistence. */
final readonly class VersionGroup
{
    public function __construct(
        public int $observedMaxVersion,
        public int $nextVersionNumber,
        public int|string|null $currentMediaId = null,
    ) {
        if ($observedMaxVersion < 0 || $nextVersionNumber < 1) {
            throw new \InvalidArgumentException('Version numbers must be positive.');
        }
    }

    public function reserveNextVersion(): int
    {
        return max($this->nextVersionNumber, $this->observedMaxVersion + 1, 1);
    }

    public function assertVersionCanBeDeleted(bool $isCurrent, int $groupCount): void
    {
        if ($isCurrent) {
            throw new RuntimeException('Cannot delete the current version. Restore a different revision first.');
        }

        if ($groupCount <= 1) {
            throw new RuntimeException('Cannot delete the only version of a file.');
        }
    }
}
