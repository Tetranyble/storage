<?php

namespace Tetranyble\Storage\Tests\Unit\Processing;

use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingService;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaContentInspection;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanResult;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaScanFailedException;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

class MediaProcessingServiceTest extends PackageTestCase
{
    public function test_clean_scan_runs_derivative_processing_and_marks_media_ready(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media();
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = $this->compatibleInspector();
        $post = Mockery::mock(MediaPostProcessor::class);

        $scanner->shouldReceive('scan')
            ->once()
            ->with(Mockery::on(fn ($target) => $target instanceof MediaScanTarget && $target->mediaId->value === $media->id))
            ->andReturn(MediaScanResult::clean('test-scanner'));
        $post->shouldReceive('process')->once()->with(
            Mockery::on(fn ($candidate) => $candidate instanceof Media && $candidate->id === $media->id),
            Mockery::any(),
        )->andReturn(['media' => $media]);

        (new MediaProcessingService($scanner, $inspector, $post))->process($media->id);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::READY, $media->processing_status);
        $this->assertSame(VirusScanStatus::CLEAN, $media->virus_scan_status);
        $this->assertSame('test-scanner', $media->scan_engine);
        $this->assertSame('application/octet-stream', $media->detected_mime_type);
        $this->assertSame(1, $media->processing_attempts);
        $this->assertNull($media->quarantined_at);
        $this->assertNotNull($media->processing_completed_at);
    }

    public function test_infected_scan_blocks_media_without_running_derivatives(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media();
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = $this->compatibleInspector();
        $post = Mockery::mock(MediaPostProcessor::class);

        $scanner->shouldReceive('scan')->once()->andReturn(
            MediaScanResult::infected('test-scanner', 'Eicar-Test-Signature')
        );
        $post->shouldNotReceive('process');

        (new MediaProcessingService($scanner, $inspector, $post))->process($media->id);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::BLOCKED, $media->processing_status);
        $this->assertSame(VirusScanStatus::INFECTED, $media->virus_scan_status);
        $this->assertSame('Eicar-Test-Signature', $media->scan_signature);
        $this->assertSame('malware_detected', $media->quarantine_reason);
        $this->assertNotNull($media->quarantined_at);
    }

    public function test_failed_scan_persists_retryable_failure_and_throws_for_queue_retry(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media();
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = $this->compatibleInspector();
        $post = Mockery::mock(MediaPostProcessor::class);
        $telemetry = Mockery::mock(StorageTelemetry::class);
        $scanner->shouldReceive('scan')->once()->andReturn(MediaScanResult::failed('test-scanner', 'daemon offline'));
        $post->shouldNotReceive('process');
        $telemetry->shouldReceive('counter')->once()->with(
            'processing.failures',
            1,
            Mockery::on(fn (array $dimensions) => ($dimensions['exception'] ?? null) === MediaScanFailedException::class),
        );
        $telemetry->shouldReceive('event')->once()->with(
            'processing.failed',
            Mockery::on(fn (array $context) => ($context['media_id'] ?? null) === $media->id),
            TelemetryLevel::ERROR,
        );

        try {
            (new MediaProcessingService($scanner, $inspector, $post, $telemetry))->process($media->id);
            $this->fail('Expected scanner failure to be retried by the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('daemon offline', $exception->getMessage());
        }

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::FAILED, $media->processing_status);
        $this->assertSame(VirusScanStatus::FAILED, $media->virus_scan_status);
        $this->assertSame('scan_failed', $media->quarantine_reason);
    }

    public function test_declared_mime_mismatch_blocks_before_malware_scan(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media(['mime_type' => 'image/jpeg']);
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = Mockery::mock(MediaContentInspector::class);
        $post = Mockery::mock(MediaPostProcessor::class);

        $inspector->shouldReceive('inspect')->once()->andReturn(new MediaContentInspection('application/pdf'));
        $scanner->shouldNotReceive('scan');
        $post->shouldNotReceive('process');

        (new MediaProcessingService($scanner, $inspector, $post))->process($media->id);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::BLOCKED, $media->processing_status);
        $this->assertSame(VirusScanStatus::SKIPPED, $media->virus_scan_status);
        $this->assertSame('content-inspection', $media->scan_engine);
        $this->assertSame('application/pdf', $media->detected_mime_type);
        $this->assertSame('unsafe_media', $media->quarantine_reason);
        $this->assertNotNull($media->scan_completed_at);
    }

    public function test_ready_media_is_idempotent_and_does_not_scan_again(): void
    {
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::READY,
            'virus_scan_status' => VirusScanStatus::CLEAN,
        ]);
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = Mockery::mock(MediaContentInspector::class);
        $post = Mockery::mock(MediaPostProcessor::class);
        $scanner->shouldNotReceive('scan');
        $inspector->shouldNotReceive('inspect');
        $post->shouldNotReceive('process');

        (new MediaProcessingService($scanner, $inspector, $post))->process($media->id);

        $media->refresh();
        $this->assertSame(0, $media->processing_attempts);
    }

    public function test_recent_processing_lease_prevents_duplicate_worker_claim(): void
    {
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::PROCESSING,
            'processing_started_at' => now(),
        ]);
        $scanner = Mockery::mock(MediaScanner::class);
        $inspector = Mockery::mock(MediaContentInspector::class);
        $post = Mockery::mock(MediaPostProcessor::class);
        $scanner->shouldNotReceive('scan');
        $inspector->shouldNotReceive('inspect');
        $post->shouldNotReceive('process');

        (new MediaProcessingService($scanner, $inspector, $post))->process($media->id);

        $media->refresh();
        $this->assertSame(0, $media->processing_attempts);
    }

    private function compatibleInspector(): MediaContentInspector
    {
        $inspector = Mockery::mock(MediaContentInspector::class);
        $inspector->shouldReceive('inspect')
            ->once()
            ->andReturn(new MediaContentInspection('application/octet-stream'));

        return $inspector;
    }

    private function media(array $overrides = []): Media
    {
        $workspace = Workspace::create(['name' => 'Processing', 'uuid' => Str::uuid()]);

        return Media::create(array_merge([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'disk' => Disk::PRIVATE,
            'path' => 'media/example.bin',
            'mime_type' => 'application/octet-stream',
            'size' => 128,
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
