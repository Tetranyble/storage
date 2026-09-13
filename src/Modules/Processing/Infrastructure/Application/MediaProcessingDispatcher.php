<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Application;

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Domain\Policy\ProcessingRetryPolicy;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Queue\Jobs\ProcessMedia;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Throwable;

class MediaProcessingDispatcher
{
    public function __construct(
        private readonly Dispatcher $bus,
        private readonly MediaProcessingService $processor,
        private readonly ?ProcessingRetryPolicy $retryPolicy = null,
    ) {}

    public function dispatch(Media $media, bool $force = false): void
    {
        if (! (bool) config('tetranyble-storage.processing.enabled', true)) {
            return;
        }

        if ($this->isExternallyHosted($media)) {
            $this->markExternalReady($media);

            return;
        }

        $reserved = $this->reserveDispatch((int) $media->getKey(), $force);
        if (! $reserved) {
            return;
        }

        if ((bool) config('tetranyble-storage.processing.inline', false)) {
            $this->processor->process((int) $reserved->getKey());

            return;
        }

        try {
            $this->bus->dispatch(new ProcessMedia((int) $reserved->getKey()));
        } catch (Throwable $exception) {
            // With Laravel's sync queue, dispatch() executes the job immediately.
            // If processing itself already claimed and failed the row, preserve
            // that worker failure; only pre-handler/enqueue failures are handoff failures.
            $current = Media::query()->find((int) $reserved->getKey());
            if ($current?->processing_status === MediaProcessingStatus::FAILED
                && (int) $current->processing_attempts > 0) {
                return;
            }

            // The Media row itself is the durable processing intent. A queue
            // outage therefore returns the row to PENDING with a due time;
            // storage:process-media can recover it without a second outbox table.
            $this->markDispatchFailure((int) $reserved->getKey(), $exception);
        }
    }

    private function reserveDispatch(int $mediaId, bool $force): ?Media
    {
        return DB::transaction(function () use ($mediaId, $force): ?Media {
            /** @var Media|null $media */
            $media = Media::query()->lockForUpdate()->find($mediaId);
            if (! $media || ! $media->path || ! $media->disk) {
                return null;
            }

            $now = now();
            if (! $force && $media->processing_available_at !== null && $media->processing_available_at->isFuture()) {
                return null;
            }

            if (! $force && $media->processing_status === MediaProcessingStatus::QUEUED
                && ! $this->policy()->dispatchLeaseExpired(
                    $media->processing_dispatched_at?->toDateTimeImmutable(),
                    $now->toDateTimeImmutable(),
                )) {
                return null;
            }

            if (! $force && $media->processing_status === MediaProcessingStatus::PROCESSING
                && $media->processing_started_at !== null) {
                $staleMinutes = max(1, (int) config('tetranyble-storage.processing.stale_after_minutes', 15));
                if ($media->processing_started_at->gt($now->copy()->subMinutes($staleMinutes))) {
                    return null;
                }
            }

            $scanEnabled = (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false);
            $scanStatus = $scanEnabled ? VirusScanStatus::PENDING : VirusScanStatus::SKIPPED;

            $media->forceFill([
                'processing_status' => MediaProcessingStatus::QUEUED,
                'processing_dispatch_attempts' => ((int) $media->processing_dispatch_attempts) + 1,
                'processing_dispatched_at' => $now,
                'processing_available_at' => null,
                'processing_started_at' => null,
                'processing_completed_at' => null,
                'virus_scan_status' => $scanStatus,
                'scan_started_at' => null,
                'scan_completed_at' => $scanEnabled ? null : $now,
                'scan_engine' => $scanEnabled ? null : 'disabled',
                'scan_signature' => null,
                'processing_error' => null,
                'quarantined_at' => $this->shouldQuarantine($scanStatus) ? ($media->quarantined_at ?: $now) : null,
                'quarantine_reason' => $this->shouldQuarantine($scanStatus) ? 'awaiting_scan' : null,
            ])->save();

            return $media->fresh();
        }, 3);
    }

    private function markDispatchFailure(int $mediaId, Throwable $exception): void
    {
        DB::transaction(function () use ($mediaId, $exception): void {
            /** @var Media|null $media */
            $media = Media::query()->lockForUpdate()->find($mediaId);
            if (! $media || in_array($media->processing_status, [MediaProcessingStatus::READY, MediaProcessingStatus::BLOCKED], true)) {
                return;
            }

            $now = now();
            $retryAt = $this->policy()->retryAt(
                $now->toDateTimeImmutable(),
                max(1, (int) $media->processing_dispatch_attempts),
            );

            $media->forceFill([
                'processing_status' => MediaProcessingStatus::PENDING,
                'processing_error' => 'Unable to enqueue media processing: '.$exception->getMessage(),
                'processing_completed_at' => null,
                'processing_available_at' => $retryAt,
                'quarantined_at' => $this->shouldQuarantine($media->virus_scan_status) ? ($media->quarantined_at ?: $now) : $media->quarantined_at,
                'quarantine_reason' => $this->shouldQuarantine($media->virus_scan_status) ? 'dispatch_failed' : $media->quarantine_reason,
            ])->save();
        }, 3);
    }

    private function markExternalReady(Media $media): void
    {
        $media->forceFill([
            'processing_status' => MediaProcessingStatus::READY,
            'virus_scan_status' => VirusScanStatus::SKIPPED,
            'processing_dispatched_at' => null,
            'processing_available_at' => null,
            'processing_completed_at' => now(),
            'scan_completed_at' => now(),
            'scan_engine' => 'external-host',
            'processing_error' => null,
            'quarantined_at' => null,
            'quarantine_reason' => null,
        ])->save();
    }

    private function policy(): ProcessingRetryPolicy
    {
        if ($this->retryPolicy) {
            return $this->retryPolicy;
        }

        $configured = config('tetranyble-storage.processing.dispatch_backoff', [10, 60, 300]);
        $backoff = is_array($configured)
            ? array_values(array_map(static fn ($seconds): int => max(1, (int) $seconds), $configured))
            : [10, 60, 300];

        return new ProcessingRetryPolicy(
            $backoff,
            max(1, (int) config('tetranyble-storage.processing.dispatch_lease_seconds', 300)),
        );
    }

    private function isExternallyHosted(Media $media): bool
    {
        if (in_array($media->disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
            return true;
        }

        $path = trim((string) $media->path);

        return $path !== '' && filter_var($path, FILTER_VALIDATE_URL) !== false;
    }

    private function shouldQuarantine(VirusScanStatus $status): bool
    {
        return (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false)
            && (bool) config('tetranyble-storage.trust.quarantine_until_clean', true)
            && ! in_array($status, [VirusScanStatus::CLEAN, VirusScanStatus::SKIPPED], true);
    }
}
