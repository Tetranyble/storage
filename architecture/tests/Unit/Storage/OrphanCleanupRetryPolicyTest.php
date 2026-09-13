<?php

namespace Tetranyble\Storage\Tests\Unit\Storage;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\Storage\Domain\Policy\OrphanCleanupRetryPolicy;

class OrphanCleanupRetryPolicyTest extends TestCase
{
    public function test_backoff_is_bounded_and_cleanup_is_abandoned_at_limit(): void
    {
        $policy = new OrphanCleanupRetryPolicy(3, [60, 300]);
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');

        $this->assertSame($now->getTimestamp() + 60, $policy->retryAt($now, 1)->getTimestamp());
        $this->assertSame($now->getTimestamp() + 300, $policy->retryAt($now, 8)->getTimestamp());
        $this->assertFalse($policy->shouldAbandon(2));
        $this->assertTrue($policy->shouldAbandon(3));
    }
}
