<?php

namespace Tetranyble\Storage\Modules\Processing\Domain\Policy;

use DateTimeImmutable;

final readonly class ProcessingRetryPolicy
{
    /** @param list<int> $backoffSeconds */
    public function __construct(
        private array $backoffSeconds = [10, 60, 300],
        private int $dispatchLeaseSeconds = 300,
    ) {}

    public function retryAt(DateTimeImmutable $now, int $dispatchAttempts): DateTimeImmutable
    {
        return $now->modify('+'.$this->delayForAttempt($dispatchAttempts).' seconds');
    }

    public function dispatchLeaseExpired(?DateTimeImmutable $dispatchedAt, DateTimeImmutable $now): bool
    {
        if ($dispatchedAt === null) {
            return true;
        }

        return $dispatchedAt->modify('+'.max(1, $this->dispatchLeaseSeconds).' seconds') <= $now;
    }

    public function delayForAttempt(int $attempt): int
    {
        $backoff = array_values(array_map(static fn (int $seconds): int => max(1, $seconds), $this->backoffSeconds));
        if ($backoff === []) {
            return 1;
        }

        return $backoff[min(max(1, $attempt) - 1, count($backoff) - 1)];
    }
}
