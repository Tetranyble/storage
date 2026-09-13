<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Application;

use Illuminate\Support\Facades\DB;
use Throwable;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline\ContentInspectionStage;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline\MalwareScanStage;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline\MediaProcessingContext;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline\MediaProcessingPipeline;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline\PostProcessingStage;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\StoragePath;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MalwareDetectedException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaScanFailedException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\UnsafeMediaException;
use Tetranyble\Storage\Modules\Trust\Domain\ValueObject\MediaScanId;

class MediaProcessingService
{
    public function __construct(
        private readonly MediaScanner $scanner,
        private readonly MediaContentInspector $contentInspector,
        private readonly MediaPostProcessor $postProcessor,
        private readonly ?StorageTelemetry $telemetry = null,
        private readonly ?MediaProcessingPipeline $pipeline = null,
    ) {}

    public function process(int $mediaId, bool $forceClaim = false): void
    {
        $media = $this->claim($mediaId, $forceClaim);
        if (! $media) {
            return;
        }

        try {
            $this->processingPipeline()->process(new MediaProcessingContext($media, $this->scanTarget($media)));
            $media->refresh();
            $media->forceFill([
                'processing_status' => MediaProcessingStatus::READY,
                'processing_completed_at' => now(),
                'processing_available_at' => null,
                'processing_error' => null,
                'quarantined_at' => null,
                'quarantine_reason' => null,
            ])->save();
        } catch (MalwareDetectedException $exception) {
            $this->recordBlocked($media, 'malware_detected');
            $this->block($media, $exception->getMessage(), 'malware_detected');
        } catch (UnsafeMediaException $exception) {
            $this->normalizeUnscannedBlock($media);
            $this->recordBlocked($media, 'unsafe_media');
            $this->block($media, $exception->getMessage(), 'unsafe_media');
        } catch (MediaScanFailedException $exception) {
            $this->fail($media, $exception->getMessage(), quarantineReason: 'scan_failed');
            $this->recordFailure($media, $exception);
            throw $exception;
        } catch (Throwable $exception) {
            if ($media->processing_status !== MediaProcessingStatus::FAILED) {
                $this->fail($media, $exception->getMessage());
            }
            $this->recordFailure($media, $exception);
            throw $exception;
        }
    }

    private function claim(int $mediaId, bool $force): ?Media
    {
        return DB::transaction(function () use ($mediaId, $force): ?Media {
            /** @var Media|null $media */
            $media = Media::query()->lockForUpdate()->find($mediaId);
            if (! $media || ! $media->path || ! $media->disk) {
                return null;
            }

            if (in_array($media->processing_status, [MediaProcessingStatus::READY, MediaProcessingStatus::BLOCKED], true)) {
                return null;
            }

            if (! $force && $media->processing_status === MediaProcessingStatus::PROCESSING
                && $media->processing_started_at !== null) {
                $leaseMinutes = max(1, (int) config('tetranyble-storage.processing.stale_after_minutes', 15));
                if ($media->processing_started_at->gt(now()->subMinutes($leaseMinutes))) {
                    return null;
                }
            }

            $scanEnabled = (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false);
            $media->forceFill([
                'processing_status' => MediaProcessingStatus::PROCESSING,
                'processing_attempts' => ((int) $media->processing_attempts) + 1,
                'processing_started_at' => now(),
                'processing_available_at' => null,
                'processing_completed_at' => null,
                'processing_error' => null,
                'virus_scan_status' => $scanEnabled ? VirusScanStatus::SCANNING : VirusScanStatus::SKIPPED,
                'scan_started_at' => $scanEnabled ? now() : null,
                'scan_completed_at' => $scanEnabled ? null : now(),
                'scan_engine' => $scanEnabled ? null : 'disabled',
            ])->save();

            return $media->fresh();
        }, 3);
    }

    private function processingPipeline(): MediaProcessingPipeline
    {
        return $this->pipeline ?? new MediaProcessingPipeline([
            new ContentInspectionStage($this->contentInspector),
            new MalwareScanStage($this->scanner),
            new PostProcessingStage($this->postProcessor),
        ]);
    }

    private function scanTarget(Media $media): MediaScanTarget
    {
        return new MediaScanTarget(
            mediaId: new MediaScanId((int) $media->getKey()),
            disk: $media->disk,
            path: new StoragePath((string) $media->path),
            size: $media->size !== null ? new FileSize((int) $media->size) : null,
            mimeType: $media->mime_type ? new MimeType((string) $media->mime_type) : null,
            originalName: $media->original_name,
        );
    }

    private function normalizeUnscannedBlock(Media $media): void
    {
        if (in_array($media->virus_scan_status, [VirusScanStatus::PENDING, VirusScanStatus::SCANNING], true)
            && $media->scan_completed_at === null) {
            $media->forceFill([
                'virus_scan_status' => VirusScanStatus::SKIPPED,
                'scan_engine' => 'content-inspection',
                'scan_completed_at' => now(),
            ])->save();
        }
    }

    private function recordBlocked(Media $media, string $reason): void
    {
        $this->telemetry?->counter('processing.blocked', 1, ['reason' => $reason]);
        $this->telemetry?->event('processing.media_blocked', [
            'media_id' => (int) $media->getKey(),
            'workspace_id' => (int) $media->workspace_id,
            'reason' => $reason,
        ], TelemetryLevel::WARNING);
    }

    private function recordFailure(Media $media, Throwable $exception): void
    {
        $this->telemetry?->counter('processing.failures', 1, ['exception' => $exception::class]);
        $this->telemetry?->event('processing.failed', [
            'media_id' => (int) $media->getKey(),
            'workspace_id' => (int) $media->workspace_id,
            'exception' => $exception::class,
        ], TelemetryLevel::ERROR);
    }

    private function block(Media $media, string $message, string $reason): void
    {
        $media->forceFill([
            'processing_status' => MediaProcessingStatus::BLOCKED,
            'processing_completed_at' => now(),
            'processing_available_at' => null,
            'processing_error' => $message,
            'quarantined_at' => $media->quarantined_at ?: now(),
            'quarantine_reason' => $reason,
        ])->save();
    }

    private function fail(Media $media, string $message, ?string $quarantineReason = null): void
    {
        $quarantine = (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false)
            && (bool) config('tetranyble-storage.trust.quarantine_until_clean', true);

        $media->forceFill([
            'processing_status' => MediaProcessingStatus::FAILED,
            'processing_completed_at' => now(),
            'processing_available_at' => null,
            'processing_error' => $message,
            'quarantined_at' => $quarantine ? ($media->quarantined_at ?: now()) : $media->quarantined_at,
            'quarantine_reason' => $quarantine ? ($quarantineReason ?: 'processing_failed') : $media->quarantine_reason,
        ])->save();
    }
}
