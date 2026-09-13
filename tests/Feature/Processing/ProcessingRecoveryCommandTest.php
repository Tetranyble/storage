<?php

namespace Tetranyble\Storage\Tests\Feature\Processing;

use Illuminate\Support\Str;
use Mockery;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class ProcessingRecoveryCommandTest extends PackageTestCase
{
    public function test_stale_processing_worker_lease_is_recovered(): void
    {
        config()->set('tetranyble-storage.processing.stale_after_minutes', 15);
        $media = $this->media([
            'processing_status' => MediaProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(20),
        ]);
        $dispatcher = Mockery::mock(MediaProcessingDispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::on(fn (Media $candidate): bool => $candidate->id === $media->id), true);
        $this->app->instance(MediaProcessingDispatcher::class, $dispatcher);

        $this->artisan('storage:process-media --limit=10')
            ->expectsOutput('Recovered/enqueued 1 media processing intent(s).')
            ->assertExitCode(0);
    }

    public function test_recent_processing_worker_lease_is_not_duplicated(): void
    {
        config()->set('tetranyble-storage.processing.stale_after_minutes', 15);
        $this->media([
            'processing_status' => MediaProcessingStatus::PROCESSING,
            'processing_started_at' => now()->subMinutes(2),
        ]);
        $dispatcher = Mockery::mock(MediaProcessingDispatcher::class);
        $dispatcher->shouldNotReceive('dispatch');
        $this->app->instance(MediaProcessingDispatcher::class, $dispatcher);

        $this->artisan('storage:process-media --limit=10')
            ->expectsOutput('Recovered/enqueued 0 media processing intent(s).')
            ->assertExitCode(0);
    }

    private function media(array $overrides = []): Media
    {
        $workspace = Workspace::create(['name' => 'Recovery', 'uuid' => Str::uuid()]);

        return Media::create(array_merge([
            'workspace_id' => $workspace->id,
            'uuid' => Str::uuid(),
            'disk' => Disk::PRIVATE,
            'path' => 'media/recovery.bin',
            'mime_type' => 'application/octet-stream',
        ], $overrides));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
