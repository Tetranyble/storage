<?php

namespace Tetranyble\Storage\Tests\Feature;

use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Contracts\DirectUploadGateway;
use Tetranyble\Storage\Modules\DirectUpload\Domain\DTO\DirectUploadObject;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\FileSize;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\MimeType;
use Tetranyble\Storage\Modules\Storage\Domain\ValueObject\Sha256Checksum;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\Fixtures\DirectUploads\FakeDirectUploadGateway;
use Tetranyble\Storage\Tests\PackageTestCase;

class DirectUploadControllerTest extends PackageTestCase
{
    private FakeDirectUploadGateway $gateway;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('tetranyble-storage.direct_uploads.enabled', true);
        $this->gateway = new FakeDirectUploadGateway;
        $this->app->instance(DirectUploadGateway::class, $this->gateway);
    }

    public function test_authenticated_user_can_create_and_finalize_a_direct_upload(): void
    {
        [$workspace, $user] = $this->workspaceAndUser();
        $sha = hash('sha256', 'hello');

        $response = $this->actingAs($user)->postJson(route('tetranyble-storage.direct-uploads.store'), [
            'original_name' => 'hello.txt',
            'expected_size' => 5,
            'sha256' => $sha,
            'mime_type' => 'text/plain',
            'disk' => 's3-public',
        ])->assertCreated()
            ->assertJsonPath('data.upload.mode', 'single');

        $uuid = $response->json('data.upload.uuid');
        $session = DirectUploadSession::query()->where('uuid', $uuid)->firstOrFail();
        $this->assertSame(5, (int) $workspace->fresh()->storage_used_bytes);

        $this->gateway->object = new DirectUploadObject(new FileSize(5), new MimeType('text/plain'), new Sha256Checksum($sha));
        $finalized = $this->actingAs($user)->postJson(
            route('tetranyble-storage.direct-uploads.finalize', $uuid),
        )->assertOk();

        $this->assertSame($uuid, Media::query()->findOrFail($finalized->json('data.media.id'))->direct_upload_session_uuid);
        $this->assertSame(DirectUploadStatus::FINALIZED, $session->fresh()->status);
    }

    public function test_progress_does_not_expose_internal_object_storage_keys(): void
    {
        [, $user] = $this->workspaceAndUser();
        $response = $this->actingAs($user)->postJson(route('tetranyble-storage.direct-uploads.store'), [
            'original_name' => 'private-name.bin',
            'expected_size' => 5,
            'sha256' => hash('sha256', 'private-name'),
            'disk' => 's3-private',
        ])->assertCreated();

        $uuid = $response->json('data.upload.uuid');
        $this->actingAs($user)
            ->getJson(route('tetranyble-storage.direct-uploads.show', $uuid))
            ->assertOk()
            ->assertJsonMissingPath('data.upload.object_key');
    }

    public function test_same_workspace_user_cannot_use_another_users_direct_upload_session(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser();
        $other = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Other',
            'email' => 'other-direct@example.test',
        ]);

        $response = $this->actingAs($owner)->postJson(route('tetranyble-storage.direct-uploads.store'), [
            'original_name' => 'owner.bin',
            'expected_size' => 5,
            'sha256' => hash('sha256', 'owner'),
            'disk' => 's3-private',
        ])->assertCreated();
        $uuid = $response->json('data.upload.uuid');

        $this->actingAs($other)
            ->getJson(route('tetranyble-storage.direct-uploads.show', $uuid))
            ->assertNotFound();

        $this->actingAs($other)
            ->deleteJson(route('tetranyble-storage.direct-uploads.destroy', $uuid))
            ->assertNotFound();
    }

    public function test_direct_upload_cannot_target_restricted_folder_without_edit_access(): void
    {
        [$workspace, $owner] = $this->workspaceAndUser();
        $viewer = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Viewer',
            'email' => 'viewer-direct@example.test',
        ]);
        $folder = Folder::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Restricted',
            'slug' => 'restricted',
            'path' => 'root/restricted',
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        $this->actingAs($viewer)->postJson(route('tetranyble-storage.direct-uploads.store'), [
            'original_name' => 'secret.bin',
            'expected_size' => 5,
            'sha256' => hash('sha256', 'secret'),
            'disk' => 's3-private',
            'folder_id' => $folder->id,
        ])->assertForbidden();

        $this->assertSame(0, DirectUploadSession::query()->count());
        $this->assertSame(0, (int) $workspace->fresh()->storage_used_bytes);
    }

    public function test_unsupported_disk_returns_server_mediated_fallback(): void
    {
        [, $user] = $this->workspaceAndUser();

        $this->actingAs($user)->postJson(route('tetranyble-storage.direct-uploads.store'), [
            'original_name' => 'fallback.bin',
            'expected_size' => 5,
            'sha256' => hash('sha256', 'fallback'),
            'disk' => 'local',
        ])->assertOk()
            ->assertJsonPath('data.mode', 'fallback')
            ->assertJsonPath('data.fallback.method', 'POST');
    }

    private function workspaceAndUser(): array
    {
        $workspace = Workspace::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Direct Upload Workspace',
        ]);
        $user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'Owner',
            'email' => Str::random(8).'@example.test',
        ]);

        return [$workspace, $user];
    }
}
