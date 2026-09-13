<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use Tetranyble\Storage\Modules\Folder\Application\CreateFolder;
use Tetranyble\Storage\Modules\Sharing\Application\CreateMediaShare;
use Tetranyble\Storage\Modules\Sharing\Application\RevokeMediaShare;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class ProductionHardeningUseCasesTest extends PackageTestCase
{
    public function test_create_folder_is_a_canonical_application_use_case(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace']);
        $owner = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Owner']);

        $folder = $this->app->make(CreateFolder::class)->handle(
            $workspace,
            'Contracts',
            actor: $owner,
        );

        $this->assertSame('Contracts', $folder->name);
        $this->assertSame($owner->id, $folder->created_by);
        $this->assertSame('root/contracts', $folder->path);
    }

    public function test_create_folder_rejects_ungranted_actor_inside_restricted_parent(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace']);
        $owner = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $create = $this->app->make(CreateFolder::class);

        $parent = $create->handle($workspace, 'Restricted', actor: $owner, scope: AccessScope::RESTRICTED);

        $this->expectException(AccessDeniedException::class);
        $create->handle($workspace, 'Should Fail', parentId: $parent->id, actor: $viewer);
    }

    public function test_media_share_create_and_revoke_no_longer_require_legacy_manager(): void
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace']);
        $owner = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $root = Folder::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $owner->id,
            'name' => 'Root',
            'slug' => 'root',
            'path' => 'root',
            'access_scope' => AccessScope::WORKSPACE,
            'is_root' => true,
        ]);
        $media = Media::query()->create([
            'workspace_id' => $workspace->id,
            'folder_id' => $root->id,
            'uploaded_by' => $owner->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->id.'/share.txt',
            'original_name' => 'share.txt',
            'mime_type' => 'text/plain',
            'size' => 5,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        $share = $this->app->make(CreateMediaShare::class)->handle(
            workspace: $workspace,
            media: $media,
            user: $owner,
            actor: $owner,
            ttlMinutes: 30,
        );

        $this->assertDatabaseHas('media_shares', ['id' => $share->id]);

        $this->app->make(RevokeMediaShare::class)->handle($workspace, $media, $share, $owner);

        $this->assertDatabaseMissing('media_shares', ['id' => $share->id]);
    }
}
