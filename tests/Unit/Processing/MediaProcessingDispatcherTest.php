<?php

namespace Tetranyble\Storage\Tests\Unit\Processing;

use Illuminate\Contracts\Bus\Dispatcher;
use Mockery;
use RuntimeException;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingService;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Queue\Jobs\ProcessMedia;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;

class MediaProcessingDispatcherTest extends PackageTestCase
{
    public function test_dispatch_marks_media_queued_and_quarantined_before_enqueuing(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media();
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn ($job) => $job instanceof ProcessMedia && $job->mediaId === $media->id));

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::QUEUED, $media->processing_status);
        $this->assertSame(1, $media->processing_dispatch_attempts);
        $this->assertNotNull($media->processing_dispatched_at);
        $this->assertSame(VirusScanStatus::PENDING, $media->virus_scan_status);
        $this->assertSame('awaiting_scan', $media->quarantine_reason);
        $this->assertNotNull($media->quarantined_at);
    }

    public function test_dispatch_failure_is_persisted_for_recovery_without_throwing(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media();
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldReceive('dispatch')->once()->andThrow(new RuntimeException('queue unavailable'));

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::PENDING, $media->processing_status);
        $this->assertStringContainsString('queue unavailable', (string) $media->processing_error);
        $this->assertNotNull($media->processing_available_at);
        $this->assertSame(1, $media->processing_dispatch_attempts);
        $this->assertSame('dispatch_failed', $media->quarantine_reason);
    }

    public function test_retry_dispatch_clears_stale_processing_and_scan_metadata(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::FAILED,
            'processing_started_at' => now()->subMinute(),
            'processing_completed_at' => now(),
            'processing_error' => 'old failure',
            'virus_scan_status' => VirusScanStatus::FAILED,
            'scan_started_at' => now()->subMinute(),
            'scan_completed_at' => now(),
            'scan_engine' => 'old-scanner',
            'scan_signature' => 'old-signature',
        ]);
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldReceive('dispatch')->once();

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::QUEUED, $media->processing_status);
        $this->assertSame(1, $media->processing_dispatch_attempts);
        $this->assertNotNull($media->processing_dispatched_at);
        $this->assertSame(VirusScanStatus::PENDING, $media->virus_scan_status);
        $this->assertNull($media->processing_started_at);
        $this->assertNull($media->processing_completed_at);
        $this->assertNull($media->processing_error);
        $this->assertNull($media->scan_started_at);
        $this->assertNull($media->scan_completed_at);
        $this->assertNull($media->scan_engine);
        $this->assertNull($media->scan_signature);
    }

    public function test_recent_queued_dispatch_is_not_enqueued_twice(): void
    {
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::QUEUED,
            'processing_dispatched_at' => now(),
            'processing_dispatch_attempts' => 1,
        ]);
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldNotReceive('dispatch');

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $this->assertSame(1, $media->fresh()->processing_dispatch_attempts);
    }

    public function test_explicit_dispatch_requeues_ready_media_after_content_or_storage_change(): void
    {
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::READY,
            'virus_scan_status' => VirusScanStatus::CLEAN,
            'processing_completed_at' => now(),
        ]);
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldReceive('dispatch')->once();

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::QUEUED, $media->processing_status);
        $this->assertNull($media->processing_completed_at);
    }

    public function test_external_media_is_marked_ready_without_queueing_or_scanning(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        $media = $this->media(['disk' => Disk::YOUTUBE, 'path' => 'https://youtu.be/demo']);
        $bus = Mockery::mock(Dispatcher::class);
        $processor = Mockery::mock(MediaProcessingService::class);
        $bus->shouldNotReceive('dispatch');
        $processor->shouldNotReceive('process');

        (new MediaProcessingDispatcher($bus, $processor))->dispatch($media);

        $media->refresh();
        $this->assertSame(MediaProcessingStatus::READY, $media->processing_status);
        $this->assertSame(VirusScanStatus::SKIPPED, $media->virus_scan_status);
        $this->assertSame('external-host', $media->scan_engine);
    }

    private function media(array $overrides = []): Media
    {
        $workspace = Workspace::create(['name' => 'Dispatch', 'uuid' => Str::uuid()]);

        return Media::create(array_merge([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'disk' => Disk::PRIVATE,
            'path' => 'media/file.pdf',
            'mime_type' => 'application/pdf',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
