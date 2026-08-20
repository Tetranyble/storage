<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\MediaStorageTransferService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaStorageTransferServiceTest extends PackageTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_copy_creates_a_non_current_media_record_and_accounts_for_quota(): void
    {
        [$workspace, $actor, $media] = $this->mediaFixture();
        $copy = $this->app->make(MediaStorageTransferService::class)->copy(
            $workspace,
            $media,
            Disk::PUBLIC,
            actor: $actor,
        );

        Storage::disk('local')->assertExists($media->path);
        Storage::disk('public')->assertExists($copy->path);
        $this->assertSame(Disk::PUBLIC, $copy->disk);
        $this->assertFalse($copy->current);
        $this->assertSame(10, $workspace->fresh()->storage_used_bytes);
    }

    public function test_move_updates_the_existing_media_storage_location(): void
    {
        [$workspace, $actor, $media] = $this->mediaFixture();
        $moved = $this->app->make(MediaStorageTransferService::class)->move(
            $workspace,
            $media,
            Disk::PUBLIC,
            actor: $actor,
        );

        Storage::disk('local')->assertMissing('media/file.txt');
        Storage::disk('public')->assertExists('media/file.txt');
        $this->assertSame($media->id, $moved->id);
        $this->assertSame(Disk::PUBLIC, $moved->disk);
        $this->assertSame(5, $workspace->fresh()->storage_used_bytes);
    }

    public function test_restricted_connected_drives_require_individual_user_grants(): void
    {
        [$workspace, $actor] = $this->mediaFixture();
        $source = ConnectedDrive::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'provider' => CloudProvider::LOCAL,
            'name' => 'Source',
            'status' => ConnectedDriveStatus::CONNECTED,
            'access_scope' => AccessScope::RESTRICTED,
        ]);
        $destination = ConnectedDrive::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'provider' => CloudProvider::LOCAL,
            'name' => 'Destination',
            'status' => ConnectedDriveStatus::CONNECTED,
            'access_scope' => AccessScope::RESTRICTED,
        ]);
        $authorizer = $this->app->make(StorageTransferAuthorizer::class);

        try {
            $authorizer->authorizeCopy($workspace, $source, $destination, $actor);
            $this->fail('Restricted drive copy should require grants.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }

        $access = $this->app->make(ResourceAccessControl::class);
        $access->grant($workspace, $source, $actor, CollaboratorRole::VIEWER);
        $access->grant($workspace, $destination, $actor, CollaboratorRole::EDITOR);
        $authorizer->authorizeCopy($workspace, $source, $destination, $actor);
        $this->addToAssertionCount(1);
    }

    private function mediaFixture(): array
    {
        $workspace = Workspace::create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Workspace',
            'storage_quota_bytes' => Workspace::DEFAULT_STORAGE_QUOTA_BYTES,
            'storage_used_bytes' => 5,
        ]);
        $actor = User::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'name' => 'User',
        ]);
        Storage::disk('local')->put('media/file.txt', 'hello');
        $media = Media::create([
            'uuid' => (string) Str::uuid(),
            'workspace_id' => $workspace->id,
            'uploaded_by' => $actor->id,
            'path' => 'media/file.txt',
            'original_name' => 'file.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'disk' => Disk::PRIVATE,
            'use' => MediaPurpose::GENERAL,
            'access_scope' => AccessScope::WORKSPACE,
            'current' => true,
        ]);

        return [$workspace, $actor, $media];
    }
}
