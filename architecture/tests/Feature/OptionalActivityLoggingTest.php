<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityFeed;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Activity\Infrastructure\NullActivityFeed;
use Tetranyble\Storage\Modules\Activity\Infrastructure\NullActivityLogger;
use Tetranyble\Storage\Modules\Folder\Application\CreateFolder;
use Tetranyble\Storage\Modules\Media\Application\RenameMedia;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Tests\PackageTestCase;

class OptionalActivityLoggingTest extends PackageTestCase
{
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
        $createFolder = $this->app->make(CreateFolder::class);
        $rename = $this->app->make(RenameMedia::class);
        $queries = $this->app->make(WorkspaceFileQueryService::class);
        $versioning = $this->app->make(MediaVersioningService::class);

        $folder = $createFolder->handle($workspace, 'Legal', null, $user);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/legal/nda.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'use' => MediaPurpose::DOCUMENT,
            'current' => true,
            'uploaded_by' => $user->id,
            'original_name' => 'nda.pdf',
        ]);
        Storage::disk('local')->put($media->path, 'nda');

        $renamed = $rename->handle($workspace, $media, 'nda-final.pdf', $user);
        $recent = $queries->recentCursorPayload($workspace, $user);
        $activity = $queries->activityCursorPayload($workspace, $user);
        $history = $versioning->activity($renamed);

        $this->assertSame('nda-final.pdf', $renamed->fresh()->original_name);
        $this->assertCount(0, $recent['folders']['data']);
        $this->assertCount(0, $recent['files']['data']);
        $this->assertCount(0, $activity['activities']);
        $this->assertCount(0, $history);
    }
}
