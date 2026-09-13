<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;

class ProcessPendingMediaCommand extends Command
{
    protected $signature = 'storage:process-media
        {--limit=100 : Maximum media rows to enqueue}
        {--retry-failed : Include previously failed processing rows}';

    protected $description = 'Recover and enqueue due media processing intents, including stale queue/worker leases.';

    public function handle(MediaProcessingDispatcher $dispatcher): int
    {
        $limit = max(1, min(10_000, (int) $this->option('limit')));
        $now = now();
        $dispatchLeaseSeconds = max(1, (int) config('tetranyble-storage.processing.dispatch_lease_seconds', 300));
        $processingStaleMinutes = max(1, (int) config('tetranyble-storage.processing.stale_after_minutes', 15));
        $retryFailed = (bool) $this->option('retry-failed');

        $count = 0;
        Media::query()
            ->whereNotNull('path')
            ->where(function (Builder $query) use ($now, $dispatchLeaseSeconds, $processingStaleMinutes, $retryFailed): void {
                $query->where(function (Builder $pending) use ($now): void {
                    $pending->where('processing_status', MediaProcessingStatus::PENDING->value)
                        ->where(function (Builder $due) use ($now): void {
                            $due->whereNull('processing_available_at')->orWhere('processing_available_at', '<=', $now);
                        });
                })->orWhere(function (Builder $queued) use ($now, $dispatchLeaseSeconds): void {
                    $queued->where('processing_status', MediaProcessingStatus::QUEUED->value)
                        ->where(function (Builder $stale) use ($now, $dispatchLeaseSeconds): void {
                            $stale->whereNull('processing_dispatched_at')
                                ->orWhere('processing_dispatched_at', '<=', $now->copy()->subSeconds($dispatchLeaseSeconds));
                        });
                })->orWhere(function (Builder $processing) use ($now, $processingStaleMinutes): void {
                    $processing->where('processing_status', MediaProcessingStatus::PROCESSING->value)
                        ->where(function (Builder $stale) use ($now, $processingStaleMinutes): void {
                            $stale->whereNull('processing_started_at')
                                ->orWhere('processing_started_at', '<=', $now->copy()->subMinutes($processingStaleMinutes));
                        });
                });

                if ($retryFailed) {
                    $query->orWhere('processing_status', MediaProcessingStatus::FAILED->value);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (Media $media) use ($dispatcher, &$count): void {
                $dispatcher->dispatch($media, force: true);
                $count++;
            });

        $this->info(sprintf('Recovered/enqueued %d media processing intent(s).', $count));

        return self::SUCCESS;
    }
}
