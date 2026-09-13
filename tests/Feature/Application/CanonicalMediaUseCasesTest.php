<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use Illuminate\Http\UploadedFile;
use Tetranyble\Storage\Http\Adapters\LaravelIncomingFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Media\Application\CreateMediaRevision;
use Tetranyble\Storage\Modules\Media\Application\DeleteMedia;
use Tetranyble\Storage\Modules\Media\Application\MoveMedia;
use Tetranyble\Storage\Modules\Media\Application\RenameMedia;
use Tetranyble\Storage\Modules\Media\Application\RestoreMedia;
use Tetranyble\Storage\Modules\Media\Application\TrashMedia;
use Tetranyble\Storage\Modules\Media\Application\UpdateMedia;
use Tetranyble\Storage\Modules\Media\Application\UploadMedia;
use Tetranyble\Storage\Modules\Upload\Application\StartResumableUpload;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Upload\Application\DTO\UploadSessionOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class CanonicalMediaUseCasesTest extends PackageTestCase
{
    public function test_update_use_case_enforces_acl_without_relying_on_a_controller(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Application ACL');
        $viewer = $this->user($workspace, 'Viewer');
        $folder = $this->folder($workspace, $owner, AccessScope::RESTRICTED);
        $media = $this->media($workspace, $owner, $folder, AccessScope::RESTRICTED);

        $this->expectException(AccessDeniedException::class);

        $this->app->make(UpdateMedia::class)->handle(
            $workspace,
            $media,
            ['description' => 'must not be written'],
            $viewer,
        );
    }

    public function test_update_use_case_rejects_media_from_another_workspace(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Application Workspace A');
        [$otherWorkspace, $otherOwner] = $this->workspaceAndUser('Application Workspace B');
        $otherFolder = $this->folder($otherWorkspace, $otherOwner);
        $otherMedia = $this->media($otherWorkspace, $otherOwner, $otherFolder);

        $this->expectException(ResourceNotFoundException::class);

        $this->app->make(UpdateMedia::class)->handle(
            $workspace,
            $otherMedia,
            ['description' => 'cross-workspace write'],
            $owner,
        );
    }

    public function test_owner_can_update_media_through_the_application_use_case(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Application Update');
        $folder = $this->folder($workspace, $owner, AccessScope::RESTRICTED);
        $media = $this->media($workspace, $owner, $folder, AccessScope::RESTRICTED);

        $updated = $this->app->make(UpdateMedia::class)->handle(
            $workspace,
            $media,
            ['description' => 'Updated through application layer'],
            $owner,
        );

        $this->assertSame('Updated through application layer', $updated->description);

        $guarded = $this->app->make(UpdateMedia::class)->handle(
            $workspace,
            $updated,
            ['path' => 'should/not/change.pdf', 'description' => 'Metadata only'],
            $owner,
        );

        $this->assertSame('workspace/documents/document.pdf', $guarded->path);
        $this->assertSame('Metadata only', $guarded->description);
    }

    public function test_upload_use_case_enforces_restricted_folder_access_directly(): void
    {
        Storage::fake('local');
        [$workspace, $owner] = $this->workspaceAndUser('Application Upload ACL');
        $viewer = $this->user($workspace, 'Viewer');
        $folder = $this->folder($workspace, $owner, AccessScope::RESTRICTED);

        $this->expectException(AccessDeniedException::class);

        $this->app->make(UploadMedia::class)->handle(
            $workspace,
            LaravelIncomingFile::fromUploadedFile(UploadedFile::fake()->create('restricted.pdf', 2, 'application/pdf')),
            MediaUploadOptions::forStandalone(
                workspaceId: $workspace->id,
                userId: $viewer->id,
                folderId: $folder->id,
                disk: Disk::PRIVATE,
                temporary: false,
            ),
            $viewer,
        );
    }

    public function test_upload_use_case_enforces_configured_size_limit_without_http_validation(): void
    {
        Storage::fake('local');
        config()->set('tetranyble-storage.uploads.max_size', 1024);
        [$workspace, $owner] = $this->workspaceAndUser('Application Upload Size');
        $folder = $this->folder($workspace, $owner);

        $this->expectException(InvalidStorageOperationException::class);
        $this->expectExceptionMessage('configured maximum size');

        $this->app->make(UploadMedia::class)->handle(
            $workspace,
            LaravelIncomingFile::fromUploadedFile(UploadedFile::fake()->create('too-large.pdf', 2, 'application/pdf')),
            MediaUploadOptions::forStandalone(
                workspaceId: $workspace->id,
                userId: $owner->id,
                folderId: $folder->id,
                disk: Disk::PRIVATE,
                temporary: false,
            ),
            $owner,
        );
    }

    public function test_media_lifecycle_use_cases_trash_restore_and_permanently_delete_consistently(): void
    {
        Storage::fake('local');
        [$workspace, $owner] = $this->workspaceAndUser('Application Lifecycle');
        $folder = $this->folder($workspace, $owner);
        $media = $this->media($workspace, $owner, $folder);
        $derivative = MediaDerivative::create([
            'media_id' => $media->id,
            'workspace_id' => $workspace->id,
            'kind' => MediaDerivativeKind::THUMBNAIL,
            'variant' => 'default',
            'format' => 'png',
            'mime_type' => 'image/png',
            'disk' => Disk::PRIVATE,
            'path' => '.derivatives/workspace-'.$workspace->id.'/'.$media->uuid.'/thumbnail-default.png',
            'size' => 5,
            'sha256' => hash('sha256', 'thumb'),
            'is_primary' => true,
            'generated_at' => now(),
        ]);
        $workspace->forceFill(['storage_used_bytes' => (int) $media->size + 5])->save();
        Storage::disk('local')->put($media->path, 'body');
        Storage::disk('local')->put($derivative->path, 'thumb');

        $this->app->make(TrashMedia::class)->handle($workspace, $media, $owner);
        $this->assertTrue(Media::withTrashed()->findOrFail($media->id)->trashed());

        $restored = $this->app->make(RestoreMedia::class)->handle(
            $workspace,
            Media::withTrashed()->findOrFail($media->id),
            $owner,
        );
        $this->assertFalse($restored->trashed());

        $this->app->make(TrashMedia::class)->handle($workspace, $restored, $owner);
        $this->app->make(DeleteMedia::class)->handle(
            $workspace,
            Media::withTrashed()->findOrFail($media->id),
            $owner,
        );

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        $this->assertDatabaseMissing('media_derivatives', ['id' => $derivative->id]);
        Storage::disk('local')->assertMissing($media->path);
        Storage::disk('local')->assertMissing($derivative->path);
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        Storage::disk('local')->assertMissing('workspace/thumbs/document.png');
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
        $this->assertDatabaseHas('activities', [
            'workspace_id' => $workspace->id,
            'type' => 'storage.media.trashed',
        ]);
        $this->assertDatabaseHas('activities', [
            'workspace_id' => $workspace->id,
            'type' => 'storage.media.restored',
        ]);
    }

    public function test_rename_use_case_owns_acl_and_physical_relocation_without_the_workspace_manager(): void
    {
        Storage::fake('local');
        [$workspace, $owner] = $this->workspaceAndUser('Application Rename');
        $folder = $this->folder($workspace, $owner);
        $media = $this->media($workspace, $owner, $folder);
        Storage::disk('local')->put($media->path, 'body');

        $renamed = $this->app->make(RenameMedia::class)->handle(
            $workspace,
            $media,
            'Quarterly Report.pdf',
            $owner,
        );

        $this->assertSame('workspace/documents/quarterly-report.pdf', $renamed->path);
        $this->assertSame('quarterly-report.pdf', $renamed->original_name);
        Storage::disk('local')->assertMissing('workspace/documents/document.pdf');
        Storage::disk('local')->assertExists('workspace/documents/quarterly-report.pdf');
        $this->assertDatabaseHas('activities', [
            'workspace_id' => $workspace->id,
            'type' => 'storage.media.renamed',
        ]);
    }

    public function test_move_use_case_owns_acl_and_physical_relocation_without_the_workspace_manager(): void
    {
        Storage::fake('local');
        [$workspace, $owner] = $this->workspaceAndUser('Application Move');
        $folder = $this->folder($workspace, $owner);
        $target = Folder::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Archive',
            'slug' => 'archive',
            'path' => 'root/archive',
            'access_scope' => AccessScope::WORKSPACE,
        ]);
        $media = $this->media($workspace, $owner, $folder);
        Storage::disk('local')->put($media->path, 'body');

        $moved = $this->app->make(MoveMedia::class)->handle(
            $workspace,
            $media,
            $target->id,
            $owner,
        );

        $this->assertSame($target->id, $moved->folder_id);
        $this->assertSame('workspace/archive/document.pdf', $moved->path);
        Storage::disk('local')->assertMissing('workspace/documents/document.pdf');
        Storage::disk('local')->assertExists('workspace/archive/document.pdf');
        $this->assertDatabaseHas('activities', [
            'workspace_id' => $workspace->id,
            'type' => 'storage.media.moved',
        ]);
    }

    public function test_create_revision_use_case_owns_acl_and_version_orchestration(): void
    {
        Storage::fake('local');
        [$workspace, $owner] = $this->workspaceAndUser('Application Revision');
        $folder = $this->folder($workspace, $owner);
        $media = $this->media($workspace, $owner, $folder);
        Storage::disk('local')->put($media->path, 'version one');
        $upload = UploadedFile::fake()->create('document.pdf', 4, 'application/pdf');

        $revision = $this->app->make(CreateMediaRevision::class)->handle(
            $workspace,
            $media,
            LaravelIncomingFile::fromUploadedFile($upload),
            $owner,
        );

        $this->assertSame(2, $revision->version_number);
        $this->assertTrue($revision->current);
        $this->assertSame($media->id, $revision->previous_version_id);
        $this->assertFalse((bool) $media->fresh()->current);
        $this->assertSame($media->fresh()->version_group_uuid, $revision->version_group_uuid);
    }

    public function test_start_resumable_upload_use_case_enforces_folder_acl_before_creating_a_session(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Application Chunk ACL');
        $viewer = $this->user($workspace, 'Viewer');
        $folder = $this->folder($workspace, $owner, AccessScope::RESTRICTED);

        try {
            $this->app->make(StartResumableUpload::class)->handle(
                $workspace,
                new UploadSessionOptions(
                    identifier: 'use-case-restricted-session',
                    upload: new MediaUploadOptions(
                        workspaceId: $workspace->id,
                        userId: $viewer->id,
                        folderId: $folder->id,
                        originalName: 'records.csv',
                    ),
                    totalChunks: 1,
                    totalSize: 100,
                ),
                $viewer,
            );
            $this->fail('Restricted resumable upload should have been denied.');
        } catch (AccessDeniedException) {
            $this->addToAssertionCount(1);
        }

        $this->assertDatabaseMissing('upload_sessions', [
            'workspace_id' => $workspace->id,
            'identifier' => 'use-case-restricted-session',
        ]);
    }

    public function test_start_resumable_upload_use_case_rejects_oversized_declared_session(): void
    {
        config()->set('tetranyble-storage.uploads.max_size', 100);
        [$workspace, $owner] = $this->workspaceAndUser('Application Chunk Size');
        $folder = $this->folder($workspace, $owner);

        $this->expectException(InvalidStorageOperationException::class);
        $this->expectExceptionMessage('configured maximum size');

        $this->app->make(StartResumableUpload::class)->handle(
            $workspace,
            new UploadSessionOptions(
                identifier: 'oversized-use-case-session',
                upload: new MediaUploadOptions(
                    workspaceId: $workspace->id,
                    userId: $owner->id,
                    folderId: $folder->id,
                    originalName: 'records.csv',
                ),
                totalChunks: 1,
                totalSize: 101,
            ),
            $owner,
        );
    }

    public function test_start_resumable_upload_use_case_creates_actor_owned_session(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Application Chunk Owner');
        $folder = $this->folder($workspace, $owner);

        $session = $this->app->make(StartResumableUpload::class)->handle(
            $workspace,
            new UploadSessionOptions(
                identifier: 'use-case-owner-session',
                upload: new MediaUploadOptions(
                    workspaceId: $workspace->id,
                    userId: $owner->id,
                    folderId: $folder->id,
                    originalName: 'records.csv',
                ),
                totalChunks: 1,
                totalSize: 100,
            ),
            $owner,
        );

        $this->assertInstanceOf(UploadSession::class, $session);
        $this->assertSame($owner->id, $session->user_id);
        $this->assertSame($folder->id, $session->folder_id);
    }

    private function workspaceAndUser(string $name): array
    {
        $workspace = Workspace::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
        ]);

        return [$workspace, $this->user($workspace, 'Owner')];
    }

    private function user(Workspace $workspace, string $name): User
    {
        return User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => $name,
            'email' => Str::slug($workspace->name.'-'.$name).'@example.com',
        ]);
    }

    private function folder(
        Workspace $workspace,
        User $owner,
        AccessScope $scope = AccessScope::WORKSPACE,
    ): Folder {
        return Folder::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Documents',
            'slug' => 'documents',
            'path' => 'root/documents',
            'access_scope' => $scope,
        ]);
    }

    private function media(
        Workspace $workspace,
        User $owner,
        Folder $folder,
        AccessScope $scope = AccessScope::WORKSPACE,
    ): Media {
        return Media::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'uploaded_by' => $owner->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspace/documents/document.pdf',
            'mime_type' => 'application/pdf',
            'size' => 4,
            'use' => MediaPurpose::GENERAL,
            'module' => 'file-centre',
            'current' => true,
            'original_name' => 'document.pdf',
            'access_scope' => $scope,
        ]);
    }
}
