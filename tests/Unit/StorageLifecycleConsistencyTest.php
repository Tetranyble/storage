<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Domain\Media\MediaDeletionService;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\MediaRelocationService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\StorageOrphan;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Tests\Fixtures\Models\Loan;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Http\UploadedFile;

class StorageLifecycleConsistencyTest extends PackageTestCase
{
    public function test_failed_media_persistence_removes_new_object_releases_quota_and_rolls_back_folders(): void
    {
        Storage::fake('public');
        $workspace = Workspace::create([
            'name' => 'Upload compensation',
            'storage_quota_bytes' => 1024 * 1024,
            'storage_used_bytes' => 0,
        ]);
        $event = 'eloquent.creating: '.Media::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('forced media persistence failure');
        });

        try {
            $this->app->make(MediaService::class)->uploadUploadedFile(
                UploadedFile::fake()->create('failure.pdf', 8, 'application/pdf'),
                MediaUploadOptions::forStandalone(
                    workspaceId: $workspace->id,
                    disk: Disk::PUBLIC,
                    directory: 'failed-uploads',
                    module: 'failed-uploads',
                    purpose: MediaPurpose::GENERAL,
                    temporary: false,
                ),
            );
            $this->fail('The forced media persistence failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced media persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, Media::query()->count());
        $this->assertSame(0, Folder::query()->count(), 'Auto-created folders must roll back with failed media persistence.');
        $this->assertSame(0, StorageOrphan::query()->count(), 'Successful rollback cleanup must not leave an orphan record.');
    }

    public function test_failed_database_delete_keeps_media_file_quota_and_orphan_registry_unchanged(): void
    {
        Storage::fake('public');
        $workspace = Workspace::create([
            'name' => 'Delete rollback',
            'storage_used_bytes' => 128,
        ]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'delete-rollback/document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 128,
            'use' => MediaPurpose::GENERAL,
        ]);
        Storage::disk('public')->put($media->path, 'document');

        $event = 'eloquent.deleting: '.Media::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('forced delete transaction failure');
        });

        try {
            $this->app->make(MediaDeletionService::class)->delete($media);
            $this->fail('The forced delete transaction failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced delete transaction failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertDatabaseHas('media', ['id' => $media->id]);
        Storage::disk('public')->assertExists($media->path);
        $this->assertSame(128, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertSame(0, StorageOrphan::query()->count());
    }

    public function test_physical_delete_failure_does_not_resurrect_media_and_is_recorded_for_retry(): void
    {
        $workspace = Workspace::create([
            'name' => 'Deferred delete',
            'storage_used_bytes' => 64,
        ]);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'deferred-delete/document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 64,
            'use' => MediaPurpose::GENERAL,
        ]);

        $files = Mockery::mock(FileSystemContract::class);
        $files->shouldReceive('exists')
            ->once()
            ->with('deferred-delete/document.pdf', Disk::PUBLIC)
            ->andReturnTrue();
        $files->shouldReceive('delete')
            ->once()
            ->with('deferred-delete/document.pdf', Disk::PUBLIC)
            ->andReturnFalse();
        $this->app->instance(FileSystemContract::class, $files);

        $this->app->make(MediaDeletionService::class)->delete($media);

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertDatabaseHas('storage_orphans', [
            'disk' => Disk::PUBLIC->value,
            'path' => 'deferred-delete/document.pdf',
            'reason' => 'media_delete',
            'attempts' => 1,
        ]);
    }

    public function test_orphan_cleanup_command_retries_and_removes_pending_object(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('orphans/leftover.bin', 'leftover');

        $this->app->make(StorageOrphanService::class)->register(
            Disk::PUBLIC,
            'orphans/leftover.bin',
            reason: 'test_cleanup',
        );

        $this->artisan('storage:cleanup-orphans --limit=10')
            ->expectsOutput('Storage orphan cleanup complete: 1 cleaned, 0 still pending.')
            ->assertExitCode(0);

        Storage::disk('public')->assertMissing('orphans/leftover.bin');
        $this->assertDatabaseMissing('storage_orphans', ['path' => 'orphans/leftover.bin']);
    }

    public function test_rename_database_failure_keeps_original_object_and_cleans_compensating_copy(): void
    {
        Storage::fake('public');
        $workspace = Workspace::create(['name' => 'Rename rollback']);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'docs/original.pdf',
            'original_name' => 'original.pdf',
            'mime_type' => 'application/pdf',
            'size' => 20,
            'use' => MediaPurpose::GENERAL,
        ]);
        Storage::disk('public')->put($media->path, 'body');

        $event = 'eloquent.updating: '.Media::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('forced rename persistence failure');
        });

        try {
            $this->app->make(MediaRelocationService::class)->rename($media, 'Renamed.pdf');
            $this->fail('The forced rename persistence failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced rename persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $this->assertSame('docs/original.pdf', $media->fresh()->path);
        Storage::disk('public')->assertExists('docs/original.pdf');
        Storage::disk('public')->assertMissing('docs/renamed.pdf');
        $this->assertSame(0, StorageOrphan::query()->count());
    }

    public function test_attach_existing_cross_workspace_failure_preserves_source_and_both_quota_ledgers(): void
    {
        Storage::fake('public');
        $sourceWorkspace = Workspace::create([
            'name' => 'Source workspace',
            'storage_used_bytes' => 100,
        ]);
        $destinationWorkspace = Workspace::create([
            'name' => 'Destination workspace',
            'storage_used_bytes' => 0,
        ]);
        $loan = Loan::create(['workspace_id' => $destinationWorkspace->id]);
        $media = Media::create([
            'workspace_id' => $sourceWorkspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'source/document.pdf',
            'original_name' => 'document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 100,
            'use' => MediaPurpose::GENERAL,
        ]);
        Storage::disk('public')->put($media->path, 'source');

        $event = 'eloquent.updating: '.Media::class;
        Event::listen($event, static function (): never {
            throw new RuntimeException('forced attach persistence failure');
        });

        try {
            $this->app->make(MediaService::class)->attachExistingMediaToModel(
                $media,
                $loan,
                MediaPurpose::BANK_STATEMENT,
            );
            $this->fail('The forced attach persistence failure should have escaped.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced attach persistence failure', $exception->getMessage());
        } finally {
            Event::forget($event);
        }

        $fresh = $media->fresh();
        $this->assertSame($sourceWorkspace->id, $fresh->workspace_id);
        $this->assertSame('source/document.pdf', $fresh->path);
        Storage::disk('public')->assertExists('source/document.pdf');
        $this->assertSame(100, (int) $sourceWorkspace->fresh()->storage_used_bytes);
        $this->assertSame(0, (int) $destinationWorkspace->fresh()->storage_used_bytes);
        $this->assertSame(0, StorageOrphan::query()->count());
    }

    public function test_usage_reconciliation_includes_trash_and_excludes_external_attachments(): void
    {
        $workspace = Workspace::create([
            'name' => 'Usage reconciliation',
            'storage_used_bytes' => 999,
        ]);

        Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'usage/active.pdf',
            'mime_type' => 'application/pdf',
            'size' => 40,
            'use' => MediaPurpose::GENERAL,
        ]);
        $trashed = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'usage/trashed.pdf',
            'mime_type' => 'application/pdf',
            'size' => 60,
            'use' => MediaPurpose::GENERAL,
        ]);
        $trashed->delete();
        Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PUBLIC,
            'path' => 'https://cdn.example.com/external.pdf',
            'mime_type' => 'application/pdf',
            'size' => 500,
            'use' => MediaPurpose::GENERAL,
        ]);

        $this->artisan('storage:reconcile-usage '.$workspace->id)
            ->expectsOutput('Storage usage reconciled for workspace '.$workspace->id.'.')
            ->assertExitCode(0);

        $this->assertSame(100, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_folder_copy_reserves_quota_and_owns_independent_original_and_thumbnail_objects(): void
    {
        Storage::fake('public');
        $workspace = Workspace::create([
            'name' => 'Folder copy lifecycle',
            'storage_used_bytes' => 55,
        ]);
        $owner = User::create([
            'workspace_id' => $workspace->id,
            'name' => 'Owner',
        ]);
        $library = $this->app->make(MediaLibraryService::class);
        $root = $library->createWorkspaceRoot($workspace);
        $folder = $library->createFolder($workspace, 'Manuals', $root);
        $child = $library->createFolder($workspace, 'PDF', $folder);
        $group = (string) Str::uuid();
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $child->id,
            'disk' => Disk::PUBLIC,
            'path' => 'workspaces/'.$workspace->uuid.'/manuals/pdf/guide.pdf',
            'thumbnail_path' => 'workspaces/'.$workspace->uuid.'/manuals/pdf/.thumbnails/guide.jpg',
            'original_name' => 'guide.pdf',
            'mime_type' => 'application/pdf',
            'size' => 55,
            'use' => MediaPurpose::GENERAL,
            'access_scope' => AccessScope::WORKSPACE,
        ]);
        $media->forceFill([
            'current' => true,
            'version_group_uuid' => $group,
            'version_number' => 1,
        ])->save();
        Storage::disk('public')->put($media->path, 'guide');
        Storage::disk('public')->put($media->thumbnail_path, 'thumb');

        $copy = $library->copyFolder($folder, $root, $owner, 'Manuals Copy');
        $copiedChild = Folder::query()->where('parent_id', $copy->id)->firstOrFail();
        $copiedMedia = Media::query()->where('folder_id', $copiedChild->id)->firstOrFail();

        $this->assertSame(110, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertNotSame($media->version_group_uuid, $copiedMedia->version_group_uuid);
        $this->assertSame(1, $copiedMedia->version_number);
        $this->assertNull($copiedMedia->previous_version_id);
        $this->assertSame(
            'workspaces/'.$workspace->uuid.'/manuals-copy/pdf/guide.pdf',
            $copiedMedia->path,
        );
        $this->assertSame(
            'workspaces/'.$workspace->uuid.'/manuals-copy/pdf/.thumbnails/guide.jpg',
            $copiedMedia->thumbnail_path,
        );
        Storage::disk('public')->assertExists($media->path);
        Storage::disk('public')->assertExists($media->thumbnail_path);
        Storage::disk('public')->assertExists($copiedMedia->path);
        Storage::disk('public')->assertExists($copiedMedia->thumbnail_path);
    }
}
