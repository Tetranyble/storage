<?php

namespace Tetranyble\Storage\Modules\Storage\Domain\Policy;

use DateTimeImmutable;

final readonly class OrphanCleanupRetryPolicy
{
    /** @param list<int> $backoffSeconds */
    public function __construct(
        private int $maxAttempts = 10,
        private array $backoffSeconds = [60, 300, 1800, 7200, 21600],
    ) {}

    public function shouldAbandon(int $attempts): bool
    {
        return $attempts >= max(1, $this->maxAttempts);
    }

    public function retryAt(DateTimeImmutable $now, int $attempts): DateTimeImmutable
    {
        $backoff = array_values(array_map(static fn (int $seconds): int => max(1, $seconds), $this->backoffSeconds));
        $delay = $backoff === [] ? 60 : $backoff[min(max(1, $attempts) - 1, count($backoff) - 1)];

        return $now->modify('+'.$delay.' seconds');
    }
}
