<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\Bulk\BulkMediaService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Application\StorageRetentionService;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class RetentionAndBulkOperationsTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_retention_dry_run_is_read_only(): void
    {
        [$workspace, $owner, $folder] = $this->workspaceOwnerFolder();
        [$media, $derivative] = $this->agedTrashedMedia($workspace, $owner, $folder);

        $result = $this->app->make(StorageRetentionService::class)->run($workspace->id, false, 100);

        $this->assertTrue($result['dry_run']);
        $this->assertSame(1, $result['media']['eligible']);
        $this->assertSame(0, $result['media']['deleted']);
        $this->assertNotNull(Media::withTrashed()->find($media->id));
        $this->assertNotNull(MediaDerivative::find($derivative->id));
        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertExists($derivative->path);
    }

    public function test_retention_apply_uses_canonical_deletion_and_releases_original_and_derivative_quota(): void
    {
        [$workspace, $owner, $folder] = $this->workspaceOwnerFolder();
        [$media, $derivative] = $this->agedTrashedMedia($workspace, $owner, $folder);

        $result = $this->app->make(StorageRetentionService::class)->run($workspace->id, true, 100);

        $this->assertFalse($result['dry_run']);
        $this->assertSame(1, $result['media']['deleted']);
        $this->assertNull(Media::withTrashed()->find($media->id));
        $this->assertNull(MediaDerivative::find($derivative->id));
        Storage::disk('local')->assertMissing($media->path);
        Storage::disk('local')->assertMissing($derivative->path);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_retention_command_requires_explicit_enablement_for_apply(): void
    {
        config()->set('tetranyble-storage.retention.enabled', false);

        $this->artisan('storage:retention --apply')
            ->expectsOutputToContain('Retention execution is disabled')
            ->assertExitCode(1);
    }

    public function test_bulk_operations_are_bounded_and_reuse_single_item_acl_lifecycle(): void
    {
        config()->set('tetranyble-storage.bulk.max_items', 2);
        [$workspace, $owner, $folder] = $this->workspaceOwnerFolder();
        $media = [
            $this->media($workspace, $owner, $folder, 'one.pdf'),
            $this->media($workspace, $owner, $folder, 'two.pdf'),
            $this->media($workspace, $owner, $folder, 'three.pdf'),
        ];

        $result = $this->app->make(BulkMediaService::class)->trash(
            $workspace,
            array_map(fn (Media $item): int => (int) $item->id, $media),
            $owner,
        );

        $this->assertSame(3, $result['requested']);
        $this->assertSame(2, $result['accepted']);
        $this->assertSame(2, $result['succeeded']);
        $this->assertTrue($result['truncated']);
        $this->assertTrue(Media::withTrashed()->findOrFail($media[0]->id)->trashed());
        $this->assertTrue(Media::withTrashed()->findOrFail($media[1]->id)->trashed());
        $this->assertFalse(Media::findOrFail($media[2]->id)->trashed());
    }

    public function test_bulk_routes_are_explicitly_separate_from_single_item_routes(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/storage.php');
        foreach (['bulk/trash', 'bulk/restore', 'bulk/delete', 'bulk/move'] as $route) {
            $this->assertStringContainsString("Route::post('".basename($route)."'", $routes);
        }
        $this->assertStringContainsString("Route::prefix('bulk')", $routes);
    }

    /** @return array{Workspace,User,Folder} */
    private function workspaceOwnerFolder(): array
    {
        $workspace = Workspace::create([
            'name' => 'Lifecycle workspace',
            'storage_quota_bytes' => 10_000_000,
        ]);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $folder = Folder::create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Root',
            'slug' => 'root',
            'path' => '/',
            'access_scope' => AccessScope::WORKSPACE,
            'is_root' => true,
        ]);

        return [$workspace, $owner, $folder];
    }

    /** @return array{Media,MediaDerivative} */
    private function agedTrashedMedia(Workspace $workspace, User $owner, Folder $folder): array
    {
        $media = $this->media($workspace, $owner, $folder, 'retained.pdf', 100);
        $derivative = MediaDerivative::create([
            'media_id' => $media->id,
            'workspace_id' => $workspace->id,
            'kind' => MediaDerivativeKind::PREVIEW,
            'variant' => 'default',
            'format' => 'jpeg',
            'mime_type' => 'image/jpeg',
            'disk' => Disk::PRIVATE,
            'path' => '.derivatives/workspace-'.$workspace->id.'/'.$media->uuid.'/preview-default.jpg',
            'size' => 20,
            'sha256' => hash('sha256', 'preview'),
            'is_primary' => true,
            'generated_at' => now()->subDays(40),
        ]);
        Storage::disk('local')->put($derivative->path, 'preview');
        $workspace->forceFill(['storage_used_bytes' => 120])->save();
        $media->delete();
        Media::withTrashed()->whereKey($media->id)->update(['deleted_at' => now()->subDays(40)]);

        return [Media::withTrashed()->findOrFail($media->id), $derivative];
    }

    private function media(Workspace $workspace, User $owner, Folder $folder, string $name, int $size = 10): Media
    {
        $path = 'retention/'.$name;
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'uploaded_by' => $owner->id,
            'disk' => Disk::PRIVATE,
            'path' => $path,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => $size,
            'use' => MediaPurpose::DOCUMENT,
            'access_scope' => AccessScope::WORKSPACE,
        ]);
        Storage::disk('local')->put($path, str_repeat('x', min($size, 1000)));

        return $media;
    }
}
