<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Tests\PackageTestCase;

class WorkspaceVisibilityQueryTest extends PackageTestCase
{
    public function test_media_visibility_is_applied_before_index_pagination(): void
    {
        [$workspace, $owner, $viewer, $root] = $this->workspaceActorsAndRoot();

        for ($i = 1; $i <= 55; $i++) {
            $this->media($workspace, $owner, $root, sprintf('a-hidden-%02d.txt', $i), AccessScope::RESTRICTED);
        }

        $visible = $this->media($workspace, $owner, $root, 'z-visible.txt', AccessScope::RESTRICTED);
        $this->app->make(ResourceAccessControl::class)
            ->grant($workspace, $visible, $viewer, CollaboratorRole::VIEWER, $owner);

        $payload = $this->app->make(WorkspaceFileQueryService::class)
            ->indexPayload($workspace, '', '', $viewer, 'name', 'asc', 1, 50);

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame(1, $payload['pagination']['last_page']);
        $this->assertCount(1, $payload['files']);
        $this->assertSame('z-visible.txt', $payload['files'][0]['name']);
    }

    public function test_media_visibility_is_applied_before_search_pagination(): void
    {
        [$workspace, $owner, $viewer, $root] = $this->workspaceActorsAndRoot();

        for ($i = 1; $i <= 4; $i++) {
            $this->media($workspace, $owner, $root, sprintf('report-hidden-%02d.pdf', $i), AccessScope::RESTRICTED);
        }

        $visible = $this->media($workspace, $owner, $root, 'report-visible.pdf', AccessScope::RESTRICTED);
        $this->app->make(ResourceAccessControl::class)
            ->grant($workspace, $visible, $viewer, CollaboratorRole::VIEWER, $owner);

        $payload = $this->app->make(WorkspaceFileQueryService::class)
            ->searchCursorPayload($workspace, 'report', $viewer, perPage: 1, sortBy: 'updated_at', sortDir: 'asc');

        $this->assertCount(1, $payload['files']['data']);
        $this->assertFalse($payload['files']['pagination']['has_more']);
        $this->assertSame('report-visible.pdf', $payload['files']['data'][0]['name']);
    }

    public function test_folder_grant_is_inherited_by_media_query_before_pagination(): void
    {
        [$workspace, $owner, $viewer, $root] = $this->workspaceActorsAndRoot();

        $folder = Folder::query()->create([
            'workspace_id' => $workspace->id,
            'parent_id' => $root->id,
            'created_by' => $owner->id,
            'name' => 'Board',
            'slug' => 'board',
            'path' => 'root/board',
            'access_scope' => AccessScope::RESTRICTED,
        ]);

        $visible = $this->media($workspace, $owner, $folder, 'minutes.pdf', AccessScope::RESTRICTED);
        $this->app->make(ResourceAccessControl::class)
            ->grant($workspace, $folder, $viewer, CollaboratorRole::VIEWER, $owner);

        $payload = $this->app->make(WorkspaceFileQueryService::class)
            ->indexPayload($workspace, 'board', '', $viewer, 'name', 'asc', 1, 50);

        $this->assertSame(1, $payload['pagination']['total']);
        $this->assertSame($visible->id, $payload['files'][0]['id']);
    }

    public function test_restricted_ancestor_keeps_workspace_child_folder_out_of_folder_query(): void
    {
        [$workspace, $owner, $viewer, $root] = $this->workspaceActorsAndRoot();

        $restricted = Folder::query()->create([
            'workspace_id' => $workspace->id,
            'parent_id' => $root->id,
            'created_by' => $owner->id,
            'name' => 'Restricted',
            'slug' => 'restricted',
            'path' => 'root/restricted',
            'access_scope' => AccessScope::RESTRICTED,
        ]);
        Folder::query()->create([
            'workspace_id' => $workspace->id,
            'parent_id' => $restricted->id,
            'created_by' => $owner->id,
            'name' => 'Child',
            'slug' => 'child',
            'path' => 'root/restricted/child',
            'access_scope' => AccessScope::WORKSPACE,
        ]);

        $this->expectException(\Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException::class);
        $this->app->make(WorkspaceFileQueryService::class)
            ->indexPayload($workspace, 'restricted', '', $viewer);
    }

    public function test_activity_visibility_is_applied_before_pagination(): void
    {
        [$workspace, $owner, $viewer, $root] = $this->workspaceActorsAndRoot();

        for ($i = 1; $i <= 55; $i++) {
            $hidden = $this->media($workspace, $owner, $root, sprintf('hidden-%02d.txt', $i), AccessScope::RESTRICTED);
            $this->activity($workspace, $hidden, sprintf('hidden %02d', $i));
        }

        $visible = $this->media($workspace, $owner, $root, 'visible.txt', AccessScope::RESTRICTED);
        $this->app->make(ResourceAccessControl::class)
            ->grant($workspace, $visible, $viewer, CollaboratorRole::VIEWER, $owner);
        $this->activity($workspace, $visible, 'visible');

        $payload = $this->app->make(WorkspaceFileQueryService::class)
            ->activityCursorPayload($workspace, $viewer, perPage: 50);

        $this->assertCount(1, $payload['activities']);
        $this->assertFalse($payload['pagination']['has_more']);
        $this->assertSame($visible->id, $payload['activities'][0]['subject_id']);
    }

    /** @return array{Workspace, User, User, Folder} */
    private function workspaceActorsAndRoot(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Workspace']);
        $owner = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Owner']);
        $viewer = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'Viewer']);
        $root = Folder::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
            'name' => 'Workspace Root',
            'slug' => 'workspace-root',
            'path' => 'root',
            'access_scope' => AccessScope::WORKSPACE,
            'is_root' => true,
        ]);

        return [$workspace, $owner, $viewer, $root];
    }

    private function media(
        Workspace $workspace,
        User $owner,
        Folder $folder,
        string $name,
        AccessScope $scope,
    ): Media {
        return Media::query()->create([
            'workspace_id' => $workspace->id,
            'folder_id' => $folder->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->id.'/'.$name,
            'original_name' => $name,
            'mime_type' => 'text/plain',
            'size' => 10,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $owner->id,
            'access_scope' => $scope,
        ]);
    }

    private function activity(Workspace $workspace, Media $media, string $description): void
    {
        Activity::query()->create([
            'workspace_id' => $workspace->id,
            'subject_id' => $media->id,
            'subject_type' => $media->getMorphClass(),
            'subject_uuid' => $media->uuid,
            'type' => 'storage.media.updated',
            'description' => $description,
        ]);
    }
}
