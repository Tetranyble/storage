<?php

namespace Tetranyble\Storage\Tests\Unit;

use Tetranyble\Storage\Domain\Media\AccessControlService;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Models\CollaboratorGrant;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Str;

class AccessControlTransferOwnershipTest extends PackageTestCase
{
    private AccessControlService $service;
    private Workspace $workspace;
    private User $owner;
    private User $newOwner;
    private Folder $folder;
    private Media $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new AccessControlService();

        $this->workspace   = Workspace::create(['name' => 'Corp', 'uuid' => Str::uuid()]);
        $this->owner    = User::create(['name' => 'Alice', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);
        $this->newOwner = User::create(['name' => 'Bob',   'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);

        $this->folder = Folder::create([
            'workspace_id'  => $this->workspace->id,
            'created_by' => $this->owner->id,
            'name'       => 'Root',
            'slug'       => 'root',
            'path'       => '/',
            'uuid'       => Str::uuid(),
        ]);

        $this->media = Media::create([
            'workspace_id'   => $this->workspace->id,
            'folder_id'   => $this->folder->id,
            'uuid'        => Str::uuid(),
            'disk'        => 'public',
            'path'        => 'doc.pdf',
            'uploaded_by' => $this->owner->id,
        ]);
    }

    public function test_transfer_folder_ownership_updates_created_by(): void
    {
        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->newOwner);

        $this->folder->refresh();
        $this->assertSame($this->newOwner->id, $this->folder->created_by);
    }

    public function test_transfer_media_ownership_updates_uploaded_by(): void
    {
        $this->service->transferOwnership($this->workspace, $this->media, $this->owner, $this->newOwner);

        $this->media->refresh();
        $this->assertSame($this->newOwner->id, $this->media->uploaded_by);
    }

    public function test_previous_owner_demoted_to_editor(): void
    {
        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->newOwner);

        $grant = CollaboratorGrant::where('user_id', $this->owner->id)
            ->where('collaboratable_type', Folder::class)
            ->where('collaboratable_id', $this->folder->id)
            ->first();

        $this->assertNotNull($grant, 'Previous owner should have an EDITOR grant');
        $this->assertSame(CollaboratorRole::EDITOR, $grant->role);
    }

    public function test_new_owner_explicit_grant_is_removed(): void
    {
        // Give newOwner an explicit collaborator grant first
        $this->service->grant($this->workspace, $this->folder, $this->newOwner, CollaboratorRole::VIEWER);

        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->newOwner);

        $grant = CollaboratorGrant::where('user_id', $this->newOwner->id)
            ->where('collaboratable_type', Folder::class)
            ->where('collaboratable_id', $this->folder->id)
            ->first();

        $this->assertNull($grant, 'New owner explicit grant should be removed (ownership comes from the field)');
    }

    public function test_transfer_to_self_is_no_op(): void
    {
        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->owner);

        $this->folder->refresh();
        $this->assertSame($this->owner->id, $this->folder->created_by);

        // No grant created for self
        $grant = CollaboratorGrant::where('user_id', $this->owner->id)->first();
        $this->assertNull($grant);
    }

    public function test_transfer_throws_if_actor_is_not_owner(): void
    {
        $imposter = User::create(['name' => 'Eve', 'uuid' => Str::uuid(), 'workspace_id' => $this->workspace->id]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->transferOwnership($this->workspace, $this->folder, $imposter, $this->newOwner);
    }

    public function test_transfer_throws_for_wrong_workspace(): void
    {
        $other  = Workspace::create(['name' => 'Other', 'uuid' => Str::uuid()]);
        $folder = Folder::create([
            'workspace_id'  => $other->id,
            'created_by' => $this->owner->id,
            'name'       => 'Other Root',
            'slug'       => 'other-root',
            'path'       => '/other',
            'uuid'       => Str::uuid(),
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);

        $this->service->transferOwnership($this->workspace, $folder, $this->owner, $this->newOwner);
    }

    public function test_new_owner_becomes_effective_owner(): void
    {
        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->newOwner);

        $role = $this->service->effectiveRole($this->workspace, $this->folder, $this->newOwner);

        $this->assertSame(CollaboratorRole::OWNER, $role);
    }

    public function test_previous_owner_retains_editor_role(): void
    {
        $this->service->transferOwnership($this->workspace, $this->folder, $this->owner, $this->newOwner);

        $role = $this->service->effectiveRole($this->workspace, $this->folder, $this->owner);

        $this->assertTrue($role?->allowsEdit(), 'Previous owner should retain at least editor-level access');
    }
}
