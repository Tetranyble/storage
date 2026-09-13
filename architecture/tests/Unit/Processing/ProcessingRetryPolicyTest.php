<?php

namespace Tetranyble\Storage\Tests\Unit\Processing;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\Processing\Domain\Policy\ProcessingRetryPolicy;

class ProcessingRetryPolicyTest extends TestCase
{
    public function test_retry_backoff_and_dispatch_lease_are_deterministic(): void
    {
        $policy = new ProcessingRetryPolicy([10, 60, 300], 300);
        $now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');

        $this->assertSame($now->getTimestamp() + 60, $policy->retryAt($now, 2)->getTimestamp());
        $this->assertFalse($policy->dispatchLeaseExpired($now, $now->modify('+299 seconds')));
        $this->assertTrue($policy->dispatchLeaseExpired($now, $now->modify('+300 seconds')));
    }
}
