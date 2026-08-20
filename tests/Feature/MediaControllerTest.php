<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaPurpose;
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
