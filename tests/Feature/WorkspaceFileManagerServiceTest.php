<?php

namespace Tetranyble\Storage\Tests\Feature;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaShareService;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WorkspaceFileManagerServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_upload_files_creates_workspace_scoped_media_records(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $user = User::create(['workspace_id' => $workspace->id, 'name' => 'User']);
        $service = $this->app->make(WorkspaceFileManagerService::class);

        $uploaded = $service->uploadFiles(
            $workspace,
            [
                UploadedFile::fake()->create('policy.pdf', 200, 'application/pdf'),
                UploadedFile::fake()->image('logo.png', 100, 100),
            ],
            null,
            $user
        );

        $this->assertCount(2, $uploaded);
        $this->assertDatabaseHas('media', [
            'workspace_id' => $workspace->id,
            'original_name' => 'policy.pdf',
            'use' => MediaPurpose::GENERAL->value,
            'module' => 'file-centre',
            'upload_strategy' => 'single',
        ]);
    }

    public function test_foreign_workspace_media_is_rejected(): void
    {
        $service = $this->app->make(WorkspaceFileManagerService::class);
        $workspaceA = Workspace::create(['name' => 'Workspace A']);
        $workspaceB = Workspace::create(['name' => 'Workspace B']);

        $media = Media::create([
            'workspace_id' => $workspaceB->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/b/workspace/foreign.txt',
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);

        $this->expectException(HttpException::class);

        $service->trashMedia($workspaceA, $media);
    }

    public function test_create_share_and_validate_access(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $user = User::create(['workspace_id' => $workspace->id, 'name' => 'User']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);
        $shares = $this->app->make(MediaShareService::class);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/a/workspace/shared.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);
        Storage::disk('local')->put($media->path, 'hello workspace');

        $share = $manager->createShare($workspace, $media, $user, ttlMinutes: 60, password: '1234');

        $shares->validateAccess($share, '1234');
        $shares->incrementDownloads($share);

        $this->assertSame(1, $share->fresh()->downloads_count);
    }

    public function test_index_payload_returns_usage_and_files(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $service = $this->app->make(WorkspaceFileManagerService::class);
        $root = Folder::create([
            'workspace_id' => $workspace->id,
            'name' => 'Workspace Root',
            'slug' => 'workspace-root',
            'path' => 'root',
            'is_root' => true,
        ]);

        Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $root->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/root/file.txt',
            'mime_type' => 'text/plain',
            'size' => 10,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
        ]);

        $payload = $service->indexPayload($workspace);

        $this->assertSame('', $payload['path']);
        $this->assertCount(1, $payload['files']);
        $this->assertArrayHasKey('usage', $payload);
    }

    public function test_restricted_folder_visibility_is_enforced_by_collaborator_role(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Private Docs', null, $owner, AccessScope::RESTRICTED);

        Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/private/report.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        $this->expectException(HttpException::class);
        $manager->indexPayload($workspace, 'private-docs', '', $viewer);
    }

    public function test_granted_viewer_can_access_restricted_folder_but_cannot_edit_or_share(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);
        $acl = $this->app->make(ResourceAccessControl::class);

        $folder = $manager->createFolder($workspace, 'Board Files', null, $owner, AccessScope::RESTRICTED);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/private/board-notes.txt',
            'mime_type' => 'text/plain',
            'size' => 12,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => AccessScope::RESTRICTED,
            'original_name' => 'board-notes.txt',
        ]);

        $manager->grantFolderAccess($workspace, $folder, $viewer, CollaboratorRole::VIEWER, $owner);

        $payload = $manager->indexPayload($workspace, 'board-files', '', $viewer);

        $this->assertCount(1, $payload['files']);
        $this->assertSame(CollaboratorRole::VIEWER->value, $acl->effectiveRole($workspace, $media, $viewer)?->value);

        try {
            $manager->renameMedia($workspace, $media, 'renamed.txt', $viewer);
            $this->fail('Viewer should not be able to rename media.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        try {
            $manager->createShare($workspace, $media, $viewer, actor: $viewer);
            $this->fail('Viewer should not be able to create shares.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }

    public function test_owner_can_grant_editor_and_editor_can_modify_restricted_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $editor = User::create(['workspace_id' => $workspace->id, 'name' => 'Editor']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Ops', null, $owner, AccessScope::RESTRICTED);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/private/ops.csv',
            'mime_type' => 'text/csv',
            'size' => 40,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => AccessScope::RESTRICTED,
            'original_name' => 'ops.csv',
        ]);
        Storage::disk('local')->put($media->path, 'a,b');

        $manager->grantMediaAccess($workspace, $media, $editor, CollaboratorRole::EDITOR, $owner);

        $renamed = $manager->renameMedia($workspace, $media, 'ops-revised.csv', $editor);

        $this->assertSame('ops-revised.csv', $renamed->original_name);
    }

    public function test_rename_folder_updates_descendant_folder_paths_and_stored_media_paths(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $parent = $manager->createFolder($workspace, 'Projects', null, $owner);
        $child = $manager->createFolder($workspace, 'Specs', $parent, $owner);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $child->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/projects/specs/spec.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'spec.pdf',
        ]);
        Storage::disk('local')->put($media->path, 'spec');

        $renamed = $manager->renameFolder($workspace, $parent, 'Initiatives', $owner);

        $this->assertSame('root/initiatives', $renamed->path);
        $this->assertSame('root/initiatives/specs', $child->fresh()->path);
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/initiatives/specs/spec.pdf',
        ]);
        Storage::disk('local')->assertMissing('workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/projects/specs/spec.pdf');
        Storage::disk('local')->assertExists('workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/initiatives/specs/spec.pdf');
    }

    public function test_move_folder_reparents_subtree_and_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $sourceParent = $manager->createFolder($workspace, 'Source', null, $owner);
        $targetParent = $manager->createFolder($workspace, 'Archive', null, $owner);
        $folder = $manager->createFolder($workspace, 'Invoices', $sourceParent, $owner);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/source/invoices/jan.csv',
            'mime_type' => 'text/csv',
            'size' => 40,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'jan.csv',
        ]);
        Storage::disk('local')->put($media->path, 'month,total');

        $moved = $manager->moveFolder($workspace, $folder, $targetParent, $owner);

        $this->assertSame($targetParent->id, $moved->parent_id);
        $this->assertSame('root/archive/invoices', $moved->path);
        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/archive/invoices/jan.csv',
        ]);
    }

    public function test_copy_folder_duplicates_subtree_and_files(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Manuals', null, $owner);
        $child = $manager->createFolder($workspace, 'PDF', $folder, $owner);

        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $child->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/manuals/pdf/guide.pdf',
            'mime_type' => 'application/pdf',
            'size' => 55,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'guide.pdf',
        ]);
        Storage::disk('local')->put($media->path, 'guide');

        $copy = $manager->copyFolder($workspace, $folder, null, $owner, 'Manuals Copy');

        $copiedChild = Folder::query()->where('parent_id', $copy->id)->firstOrFail();
        $copiedMedia = Media::query()->where('folder_id', $copiedChild->id)->firstOrFail();

        $this->assertSame('root/manuals-copy', $copy->path);
        $this->assertSame('root/manuals-copy/pdf', $copiedChild->path);
        $this->assertNotSame($media->id, $copiedMedia->id);
        $this->assertSame('workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/manuals-copy/pdf/guide.pdf', $copiedMedia->path);
        Storage::disk('local')->assertExists($media->path);
        Storage::disk('local')->assertExists($copiedMedia->path);
    }

    public function test_trash_and_restore_folder_applies_to_subtree_and_media(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Shared', null, $owner);
        $child = $manager->createFolder($workspace, 'Docs', $folder, $owner);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $child->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/shared/docs/file.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'file.txt',
        ]);

        $manager->trashFolder($workspace, $folder, $owner);

        $trash = $manager->trashPayload($workspace);

        $this->assertCount(2, $trash['folders']);
        $this->assertCount(1, $trash['files']);
        $this->assertSoftDeleted('folders', ['id' => $folder->id]);
        $this->assertSoftDeleted('folders', ['id' => $child->id]);
        $this->assertSoftDeleted('media', ['id' => $media->id]);

        $manager->restoreFolder($workspace, Folder::withTrashed()->findOrFail($folder->id), $owner);

        $this->assertDatabaseHas('folders', ['id' => $folder->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('folders', ['id' => $child->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('media', ['id' => $media->id, 'deleted_at' => null]);
    }

    public function test_permanently_delete_folder_removes_subtree_media_and_files(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Temp', null, $owner);
        $child = $manager->createFolder($workspace, 'Nested', $folder, $owner);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $child->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/temp/nested/data.json',
            'mime_type' => 'application/json',
            'size' => 15,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'data.json',
        ]);
        Storage::disk('local')->put($media->path, '{"ok":true}');

        $manager->trashFolder($workspace, $folder, $owner);
        $trashedFolder = Folder::withTrashed()->findOrFail($folder->id);

        $manager->permanentlyDeleteFolder($workspace, $trashedFolder, $owner);

        $this->assertDatabaseMissing('folders', ['id' => $folder->id]);
        $this->assertDatabaseMissing('folders', ['id' => $child->id]);
        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('local')->assertMissing('workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/temp/nested/data.json');
    }

    public function test_upload_media_revision_lists_versions_and_restores_selected_revision(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Contracts', null, $owner);
        $media = $manager->uploadFiles(
            $workspace,
            [UploadedFile::fake()->create('master.pdf', 25, 'application/pdf')],
            $folder,
            $owner
        )->first();

        $revision = $manager->uploadMediaRevision(
            $workspace,
            $media,
            UploadedFile::fake()->create('master.pdf', 30, 'application/pdf'),
            $owner
        );

        $versions = $manager->listMediaVersions($workspace, $revision, $owner);

        $this->assertCount(2, $versions['versions']);
        $this->assertCount(2, $versions['history']);
        $this->assertSame(2, $versions['versions'][0]['version_number']);
        $this->assertTrue($versions['versions'][0]['current']);
        $this->assertSame('storage.media.'.MediaRevisionEventType::REVISION_UPLOADED->value, $versions['history'][0]['type']);
        $this->assertSame($media->id, data_get($versions['history'][0], 'meta.source_media_id'));

        $restored = $manager->restoreMediaRevision($workspace, $media, $owner);

        $this->assertTrue($restored->current);
        $this->assertSame(3, $restored->version_number);
        $this->assertSame($media->id, $restored->previous_version_id);
        $this->assertDatabaseHas('activities', [
            'subject_id' => $restored->id,
            'subject_type' => $restored->getMorphClass(),
            'type' => 'storage.media.'.MediaRevisionEventType::REVISION_RESTORED->value,
            'user_id' => $owner->id,
        ]);
    }

    public function test_starred_payload_returns_only_actor_stars_and_supports_unstarring(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $other = User::create(['workspace_id' => $workspace->id, 'name' => 'Other']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Favorites', null, $owner);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/favorites/brief.txt',
            'mime_type' => 'text/plain',
            'size' => 9,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'original_name' => 'brief.txt',
        ]);

        $manager->starFolder($workspace, $folder, $owner);
        $manager->starMedia($workspace, $media, $owner);
        $manager->starFolder($workspace, $folder, $other);

        $payload = $manager->starredPayload($workspace, $owner);

        $this->assertCount(1, $payload['folders']['data']);
        $this->assertCount(1, $payload['files']['data']);
        $this->assertSame($folder->id, $payload['folders']['data'][0]['id']);
        $this->assertSame($media->id, $payload['files']['data'][0]['id']);

        $manager->unstarFolder($workspace, $folder, $owner);
        $manager->unstarMedia($workspace, $media, $owner);

        $payload = $manager->starredPayload($workspace, $owner);

        $this->assertSame([], $payload['folders']['data']->all());
        $this->assertSame([], $payload['files']['data']->all());
        $this->assertDatabaseHas('resource_stars', [
            'workspace_id' => $workspace->id,
            'user_id' => $other->id,
            'starable_type' => $folder->getMorphClass(),
            'starable_id' => $folder->id,
        ]);
    }

    public function test_shared_with_me_payload_returns_direct_collaborator_grants(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Board', null, $owner, AccessScope::RESTRICTED);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/board/agenda.pdf',
            'mime_type' => 'application/pdf',
            'size' => 90,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => AccessScope::RESTRICTED,
            'original_name' => 'agenda.pdf',
        ]);

        $manager->grantFolderAccess($workspace, $folder, $viewer, CollaboratorRole::VIEWER, $owner);
        $manager->grantMediaAccess($workspace, $media, $viewer, CollaboratorRole::COMMENTER, $owner);

        $payload = $manager->sharedWithMePayload($workspace, $viewer);

        $this->assertCount(1, $payload['folders']['data']);
        $this->assertCount(1, $payload['files']['data']);
        $this->assertSame($folder->id, $payload['folders']['data'][0]['id']);
        $this->assertSame($media->id, $payload['files']['data'][0]['id']);
    }

    public function test_recent_and_activity_payloads_return_logged_storage_events_and_honor_acl(): void
    {
        $workspace = Workspace::create(['name' => 'Workspace']);
        $owner = User::create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $manager = $this->app->make(WorkspaceFileManagerService::class);

        $folder = $manager->createFolder($workspace, 'Legal', null, $owner, AccessScope::RESTRICTED);
        $media = Media::create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->uuid.'/file-centre/2026/06/06/legal/nda.pdf',
            'mime_type' => 'application/pdf',
            'size' => 120,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => AccessScope::RESTRICTED,
            'original_name' => 'nda.pdf',
        ]);
        Storage::disk('local')->put($media->path, 'nda');

        $manager->renameMedia($workspace, $media, 'nda-final.pdf', $owner);
        $manager->createShare($workspace, $media->fresh(), $owner, actor: $owner, ttlMinutes: 30);
        $manager->setMediaAccessScope($workspace, $media->fresh(), AccessScope::RESTRICTED, $owner);

        $viewerRecent = $manager->recentPayload($workspace, $viewer);
        $viewerActivity = $manager->activityPayload($workspace, $viewer);

        $this->assertCount(0, $viewerRecent['folders']['data']);
        $this->assertCount(0, $viewerRecent['files']['data']);
        $this->assertCount(0, $viewerActivity['activities']);

        $manager->grantFolderAccess($workspace, $folder, $viewer, CollaboratorRole::VIEWER, $owner);

        $ownerRecent = $manager->recentPayload($workspace, $owner);
        $ownerActivity = $manager->activityPayload($workspace, $owner);
        $viewerRecent = $manager->recentPayload($workspace, $viewer);
        $viewerActivity = $manager->activityPayload($workspace, $viewer);

        $this->assertSame($folder->id, $ownerRecent['folders']['data'][0]['id']);
        $this->assertSame($media->id, $ownerRecent['files']['data'][0]['id']);
        $this->assertSame('storage.folder.access_granted', $ownerActivity['activities'][0]['type']);
        $this->assertSame('storage.folder.access_granted', $viewerActivity['activities'][0]['type']);
        $this->assertSame($media->id, $viewerRecent['files']['data'][0]['id']);
        $this->assertNotEmpty(collect($ownerActivity['activities'])->firstWhere('type', 'storage.media.shared'));
        $this->assertNotEmpty(collect($ownerActivity['activities'])->firstWhere('type', 'storage.media.scope_changed'));
        $this->assertNotEmpty(collect($ownerActivity['activities'])->firstWhere('type', 'storage.media.renamed'));
    }
}
