<?php

namespace Tetranyble\Storage\Tests\Unit\Processing;

use Illuminate\Contracts\Queue\ShouldQueue;
use Tetranyble\Storage\Tests\PackageTestCase;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Queue\Jobs\ProcessMedia;

class ProcessMediaJobTest extends PackageTestCase
{
    public function test_job_is_queued_after_commit_and_uses_configured_retry_policy(): void
    {
        config([
            'tetranyble-storage.processing.tries' => 4,
            'tetranyble-storage.processing.backoff' => [5, 30, 120],
            'tetranyble-storage.processing.timeout_seconds' => 90,
            'tetranyble-storage.processing.connection' => 'database',
            'tetranyble-storage.processing.queue' => 'media-processing',
        ]);

        $job = new ProcessMedia(42);

        $this->assertInstanceOf(ShouldQueue::class, $job);
        $this->assertTrue($job->afterCommit);
        $this->assertSame(4, $job->tries);
        $this->assertSame(4, $job->maxExceptions);
        $this->assertSame(90, $job->timeout);
        $this->assertTrue($job->failOnTimeout);
        $this->assertSame([5, 30, 120], $job->backoff());
        $this->assertSame(42, $job->mediaId);
    }
}
