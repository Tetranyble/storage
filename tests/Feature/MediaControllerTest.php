<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaControllerTest extends PackageTestCase
{
    public function test_media_show_is_scoped_to_the_authenticated_workspace(): void
    {
        [$workspace, $user] = $this->workspaceAndUser('One');
        [$otherWorkspace] = $this->workspaceAndUser('Two');
        $ownMedia = $this->mediaFor($workspace);
        $otherMedia = $this->mediaFor($otherWorkspace);

        $this->actingAs($user)
            ->getJson(route('tetranyble-storage.media.show', $ownMedia->uuid))
            ->assertOk()
            ->assertJsonPath('data.media.uuid', $ownMedia->uuid);

        $this->actingAs($user)
            ->getJson(route('tetranyble-storage.media.show', $otherMedia->uuid))
            ->assertNotFound();
    }

    public function test_media_metadata_can_be_updated_through_the_package_controller(): void
    {
        [$workspace, $user] = $this->workspaceAndUser('One');
        $media = $this->mediaFor($workspace);

        $this->actingAs($user)
            ->patchJson(route('tetranyble-storage.media.update', $media->uuid), [
                'description' => 'Updated description',
                'custom_properties' => ['source' => 'test'],
            ])
            ->assertOk()
            ->assertJsonPath('data.media.description', 'Updated description');

        $this->assertDatabaseHas('media', [
            'id' => $media->id,
            'description' => 'Updated description',
        ]);
    }

    public function test_controller_routes_use_configured_classes(): void
    {
        $route = Route::getRoutes()->getByName('tetranyble-storage.media.show');

        $this->assertSame(
            config('tetranyble-storage.routes.controllers.media').'@show',
            $route?->getActionName(),
        );
    }

    public function test_resumable_upload_sessions_are_scoped_to_the_authenticated_workspace(): void
    {
        [, $user] = $this->workspaceAndUser('One');
        [, $otherUser] = $this->workspaceAndUser('Two');

        $response = $this->actingAs($user)
            ->postJson(route('tetranyble-storage.uploads.store'), [
                'identifier' => 'browser-upload-1',
                'original_name' => 'records.csv',
                'total_chunks' => 2,
                'total_size' => 100,
                'purpose' => MediaPurpose::IMPORT_SOURCE->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.upload.total_chunks', 2);

        $sessionUuid = $response->json('data.upload.uuid');

        $this->actingAs($otherUser)
            ->getJson(route('tetranyble-storage.uploads.show', $sessionUuid))
            ->assertNotFound();
    }


    public function test_resumable_upload_session_cannot_be_used_by_another_user_in_the_same_workspace(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Shared Workspace Session');
        $otherUser = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Other User',
            'email' => 'same-workspace-session@example.com',
        ]);

        $response = $this->actingAs($owner)
            ->postJson(route('tetranyble-storage.uploads.store'), [
                'identifier' => 'owner-session',
                'original_name' => 'records.csv',
                'total_chunks' => 1,
                'total_size' => 100,
            ])
            ->assertCreated();

        $sessionUuid = $response->json('data.upload.uuid');

        $this->actingAs($otherUser)
            ->getJson(route('tetranyble-storage.uploads.show', $sessionUuid))
            ->assertNotFound();

        $this->actingAs($otherUser)
            ->deleteJson(route('tetranyble-storage.uploads.destroy', $sessionUuid))
            ->assertNotFound();
    }

    public function test_public_share_download_is_limited_to_the_shared_media_workspace(): void
    {
        Storage::fake('local');
        [$workspace] = $this->workspaceAndUser('One');
        $media = $this->mediaFor($workspace);
        Storage::disk('local')->put($media->path, 'shared content');
        $share = MediaShare::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'download',
        ]);

        $this->get(route('tetranyble-storage.shares.download', $share->token))
            ->assertOk()
            ->assertStreamedContent('shared content');
    }

    public function test_remote_media_can_be_imported_to_an_optional_storage_driver(): void
    {
        Storage::fake('public');
        config()->set('tetranyble-storage.remote.block_private_networks', false);
        [$workspace, $user] = $this->workspaceAndUser('One');
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
        Http::fake([
            'https://assets.example.com/avatar.png' => Http::response($png, 200, [
                'Content-Type' => 'image/png',
                'Content-Length' => strlen($png),
            ]),
        ]);

        $response = $this->actingAs($user)
            ->postJson(route('tetranyble-storage.media.import-url'), [
                'url' => 'https://assets.example.com/avatar.png',
                'purpose' => MediaPurpose::PROFILE->value,
                'driver' => 'public',
                'temporary' => false,
            ])
            ->assertCreated()
            ->assertJsonPath('data.media.disk', 'public')
            ->assertJsonPath('data.media.workspace_id', $workspace->id);

        $media = Media::query()->where('uuid', $response->json('data.media.uuid'))->firstOrFail();
        Storage::disk('public')->assertExists($media->path);
    }



    public function test_owner_can_read_update_and_upload_into_a_restricted_folder(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Restricted Owner');
        [$folder, $media] = $this->restrictedMediaFor($workspace, $owner);

        $this->actingAs($owner)
            ->getJson(route('tetranyble-storage.media.show', $media->uuid))
            ->assertOk();

        $this->actingAs($owner)
            ->patchJson(route('tetranyble-storage.media.update', $media->uuid), [
                'description' => 'Owner update',
            ])
            ->assertOk()
            ->assertJsonPath('data.media.description', 'Owner update');

        $this->actingAs($owner)
            ->postJson(route('tetranyble-storage.media.store'), [
                'file' => UploadedFile::fake()->create('owner-upload.pdf', 10, 'application/pdf'),
                'folder_id' => $folder->id,
            ])
            ->assertCreated();
    }

    public function test_restricted_media_cannot_be_read_through_the_direct_media_endpoint(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Restricted Read');
        $viewer = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Viewer',
            'email' => 'restricted-read-viewer@example.com',
        ]);
        [$folder, $media] = $this->restrictedMediaFor($workspace, $owner);

        $this->actingAs($viewer)
            ->getJson(route('tetranyble-storage.media.show', $media->uuid))
            ->assertForbidden();
    }

    public function test_restricted_media_cannot_be_updated_through_the_direct_media_endpoint(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Restricted Update');
        $viewer = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Viewer',
            'email' => 'restricted-update-viewer@example.com',
        ]);
        [, $media] = $this->restrictedMediaFor($workspace, $owner);

        $this->actingAs($viewer)
            ->patchJson(route('tetranyble-storage.media.update', $media->uuid), [
                'description' => 'Should not be written',
            ])
            ->assertForbidden();

        $this->assertNotSame('Should not be written', $media->fresh()->description);
    }

    public function test_direct_upload_cannot_target_a_restricted_folder_without_edit_access(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Restricted Upload');
        $viewer = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Viewer',
            'email' => 'restricted-upload-viewer@example.com',
        ]);
        [$folder] = $this->restrictedMediaFor($workspace, $owner);

        $this->actingAs($viewer)
            ->postJson(route('tetranyble-storage.media.store'), [
                'file' => UploadedFile::fake()->create('private.pdf', 10, 'application/pdf'),
                'folder_id' => $folder->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('media', [
            'workspace_id' => $workspace->id,
            'original_name' => 'private.pdf',
        ]);
    }

    public function test_chunked_upload_cannot_target_a_restricted_folder_without_edit_access(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser('Restricted Chunked');
        $viewer = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Viewer',
            'email' => 'restricted-chunked-viewer@example.com',
        ]);
        [$folder] = $this->restrictedMediaFor($workspace, $owner);

        $this->actingAs($viewer)
            ->postJson(route('tetranyble-storage.uploads.store'), [
                'identifier' => 'restricted-upload',
                'original_name' => 'private.pdf',
                'total_chunks' => 1,
                'total_size' => 1024,
                'folder_id' => $folder->id,
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('upload_sessions', [
            'workspace_id' => $workspace->id,
            'identifier' => 'restricted-upload',
        ]);
    }

    public function test_direct_upload_honors_the_configured_maximum_size(): void
    {
        [, $user] = $this->workspaceAndUser('Upload Limit');
        config()->set('tetranyble-storage.uploads.max_size', 1024);

        $this->actingAs($user)
            ->postJson(route('tetranyble-storage.media.store'), [
                'file' => UploadedFile::fake()->create('too-large.pdf', 2, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    public function test_library_upload_uses_the_same_configured_maximum_size_as_direct_uploads(): void
    {
        [, $user] = $this->workspaceAndUser('Library Upload Limit');
        config()->set('tetranyble-storage.uploads.max_size', 1024);

        $this->actingAs($user)
            ->postJson(route('tetranyble-storage.library.upload'), [
                'files' => [UploadedFile::fake()->create('too-large-library.pdf', 2, 'application/pdf')],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('files.0');
    }

    public function test_chunked_session_rejects_a_declared_total_size_above_the_configured_limit(): void
    {
        [, $user] = $this->workspaceAndUser('Chunk Limit');
        config()->set('tetranyble-storage.uploads.max_size', 1024);

        $this->actingAs($user)
            ->postJson(route('tetranyble-storage.uploads.store'), [
                'identifier' => 'too-large-chunked-upload',
                'original_name' => 'too-large.pdf',
                'total_chunks' => 1,
                'total_size' => 2048,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('total_size');
    }

    public function test_view_only_share_cannot_use_the_download_endpoint(): void
    {
        Storage::fake('local');
        [$workspace] = $this->workspaceAndUser('View Share');
        $media = $this->mediaFor($workspace);
        Storage::disk('local')->put($media->path, 'shared content');
        $share = MediaShare::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'shareable_type' => Media::class,
            'shareable_id' => $media->id,
            'token' => Str::random(32),
            'access_level' => 'view',
        ]);

        $this->get(route('tetranyble-storage.shares.download', $share->token))
            ->assertForbidden();

        $this->assertSame(0, $share->fresh()->downloads_count);
    }

    private function workspaceAndUser(string $name): array
    {
        $workspace = Workspace::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
        ]);
        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => "{$name} User",
            'email' => strtolower($name).'@example.com',
        ]);

        return [$workspace, $user];
    }


    private function restrictedMediaFor(Workspace $workspace, User $owner): array
    {
        $folder = Folder::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Restricted',
            'slug' => 'restricted',
            'path' => 'root/restricted',
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        $media = Media::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'uploaded_by' => $owner->id,
            'path' => 'media/restricted.txt',
            'original_name' => 'restricted.txt',
            'mime_type' => 'text/plain',
            'disk' => 'local',
            'use' => MediaPurpose::GENERAL,
            'access_scope' => AccessScope::RESTRICTED,
            'current' => true,
        ]);

        return [$folder, $media];
    }

    private function mediaFor(Workspace $workspace): Media
    {
        return Media::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'path' => 'media/test.txt',
            'original_name' => 'test.txt',
            'mime_type' => 'text/plain',
            'disk' => 'local',
            'use' => MediaPurpose::GENERAL,
            'access_scope' => AccessScope::WORKSPACE,
            'current' => true,
        ]);
    }
}
