<?php

namespace Tetranyble\Storage\Tests\Feature;

use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Domain\Activity\NullActivityFeed;
use Tetranyble\Storage\Domain\Activity\NullActivityLogger;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class OptionalActivityLoggingTest extends PackageTestCase
{
    /**
     * @param  Application  $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('tetranyble-storage.activities.enabled', false);
        $app['config']->set('tetranyble-storage.activities.load_migrations', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Schema::dropIfExists('activities');
    }

    public function test_activity_contracts_fall_back_to_noop_implementations(): void
    {
        $this->assertInstanceOf(NullActivityLogger::class, $this->app->make(ActivityLogger::class));
        $this->assertInstanceOf(NullActivityFeed::class, $this->app->make(ActivityFeed::class));
    }

    public function test_storage_flows_continue_without_the_package_activity_table(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $user = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);
        $versioning = $this->app->make(MediaVersioningService::class);

        $folder = $manager->createFolder($workspace, 'Legal', null, $user);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/legal/nda.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $user->id,
            'original_name' => 'nda.pdf',
        ]);
        Storage::disk('local')->put($media->path, 'nda');

        $renamed = $manager->renameMedia($workspace, $media, 'nda-final.pdf', $user);
        $recent = $manager->recentPayload($workspace, $user);
        $activity = $manager->activityPayload($workspace, $user);
        $history = $versioning->activity($renamed);

        $this->assertSame('nda-final.pdf', $renamed->fresh()->original_name);
        $this->assertCount(0, $recent['folders']['data']);
        $this->assertCount(0, $recent['files']['data']);
        $this->assertCount(0, $activity['activities']);
        $this->assertCount(0, $history);
    }
}
