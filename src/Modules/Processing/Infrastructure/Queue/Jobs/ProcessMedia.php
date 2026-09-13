<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Queue\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingService;

class ProcessMedia implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;
    public bool $failOnTimeout = true;
    public int $tries;
    public int $maxExceptions;
    public int $timeout;
    public ?string $connection;
    public ?string $queue;

    public function __construct(public readonly int $mediaId)
    {
        $this->tries = max(1, (int) config('tetranyble-storage.processing.tries', 3));
        $this->maxExceptions = $this->tries;
        $this->timeout = max(1, (int) config('tetranyble-storage.processing.timeout_seconds', 60));
        $this->connection = config('tetranyble-storage.processing.connection') ?: null;
        $this->queue = config('tetranyble-storage.processing.queue') ?: null;
    }

    public function backoff(): array
    {
        $configured = config('tetranyble-storage.processing.backoff', [10, 60, 300]);

        return array_values(array_map(
            static fn ($seconds): int => max(1, (int) $seconds),
            is_array($configured) ? $configured : [10, 60, 300],
        ));
    }

    public function handle(MediaProcessingService $processor): void
    {
        // A redelivered first-attempt job is a duplicate and is rejected by the
        // DB lease. A genuine queue retry may reclaim a row left PROCESSING by
        // a hard worker timeout that could not execute a catch/finally block.
        $processor->process($this->mediaId, forceClaim: $this->attempts() > 1);
    }

    /** @return list<string> */
    public function tags(): array
    {
        return ['tetranyble-storage', 'media-processing', 'media:'.$this->mediaId];
    }
}
