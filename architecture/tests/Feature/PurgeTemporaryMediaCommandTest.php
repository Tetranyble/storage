<?php

namespace Tetranyble\Storage\Tests\Feature;

use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Storage;

class PurgeTemporaryMediaCommandTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_command_purges_expired_temporary_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $path = 'workspaces/test/tmp.txt';

        Storage::disk('local')->put($path, 'temp');

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => $path,
            'size' => 4,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'is_temporary' => true,
            'temporary_expires_at' => now()->subMinute(),
        ]);

        $this->artisan('tetranyble-storage:purge-temp')
            ->expectsOutput('Purged 1 temporary media items.')
            ->assertSuccessful();

        Storage::disk('local')->assertMissing($path);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
    }
}
