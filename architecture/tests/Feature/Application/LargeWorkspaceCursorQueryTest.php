<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Activity\Infrastructure\Persistence\Eloquent\Models\Activity;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Tests\PackageTestCase;

class LargeWorkspaceCursorQueryTest extends PackageTestCase
{
    public function test_search_cursor_paginates_folders_and_files_independently(): void
    {
        [$workspace, $user, $root] = $this->workspaceUserAndRoot();

        foreach (['alpha-1', 'alpha-2', 'alpha-3'] as $name) {
            $this->folder($workspace, $user, $root, $name);
            $this->media($workspace, $user, $root, $name.'.pdf');
        }

        $queries = $this->app->make(WorkspaceFileQueryService::class);
        $first = $queries->searchCursorPayload($workspace, 'alpha', $user, perPage: 2, sortBy: 'name', sortDir: 'asc');

        $this->assertCount(2, $first['folders']['data']);
        $this->assertCount(2, $first['files']['data']);
        $this->assertNotNull($first['folders']['pagination']['next_cursor']);
        $this->assertNotNull($first['files']['pagination']['next_cursor']);
        $this->assertTrue($first['folders']['pagination']['has_more']);
        $this->assertTrue($first['files']['pagination']['has_more']);

        $second = $queries->searchCursorPayload(
            $workspace,
            'alpha',
            $user,
            folderCursor: $first['folders']['pagination']['next_cursor'],
            fileCursor: $first['files']['pagination']['next_cursor'],
            perPage: 2,
            sortBy: 'name',
            sortDir: 'asc',
        );

        $this->assertCount(1, $second['folders']['data']);
        $this->assertCount(1, $second['files']['data']);
        $this->assertFalse($second['folders']['pagination']['has_more']);
        $this->assertFalse($second['files']['pagination']['has_more']);
        $this->assertSame('alpha-3', $second['folders']['data'][0]['name']);
        $this->assertSame('alpha-3.pdf', $second['files']['data'][0]['name']);
    }

    public function test_query_service_exposes_cursor_search_without_duplicate_offset_search_api(): void
    {
        $reflection = new \ReflectionClass(WorkspaceFileQueryService::class);

        $this->assertTrue($reflection->hasMethod('searchCursorPayload'));
        $this->assertFalse($reflection->hasMethod('searchPayload'));
        $this->assertFalse($reflection->hasMethod('recentPayload'));
        $this->assertFalse($reflection->hasMethod('activityPayload'));
    }

    public function test_cursor_page_size_is_clamped_below_http_layer(): void
    {
        config()->set('tetranyble-storage.queries.max_per_page', 2);
        [$workspace, $user, $root] = $this->workspaceUserAndRoot();

        foreach (['bounded-1', 'bounded-2', 'bounded-3'] as $name) {
            $this->media($workspace, $user, $root, $name.'.pdf');
        }

        $payload = $this->app->make(WorkspaceFileQueryService::class)
            ->searchCursorPayload($workspace, 'bounded', $user, perPage: 999);

        $this->assertSame(2, $payload['files']['pagination']['per_page']);
        $this->assertCount(2, $payload['files']['data']);
    }

    public function test_cursor_search_does_not_emit_offset_queries(): void
    {
        [$workspace, $user, $root] = $this->workspaceUserAndRoot();
        foreach (range(1, 5) as $i) {
            $this->media($workspace, $user, $root, 'offset-free-'.$i.'.pdf');
        }

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->app->make(WorkspaceFileQueryService::class)
            ->searchCursorPayload($workspace, 'offset-free', $user, perPage: 2);
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(' offset ', strtolower((string) ($query['query'] ?? '')));
        }
    }

    public function test_invalid_cursor_fails_fast_in_application_layer(): void
    {
        [$workspace, $user] = $this->workspaceUserAndRoot();

        $this->expectException(InvalidStorageOperationException::class);
        $this->app->make(WorkspaceFileQueryService::class)
            ->activityCursorPayload($workspace, $user, 'not-a-valid-cursor');
    }

    public function test_activity_cursor_uses_stable_created_at_and_id_order_without_duplicates(): void
    {
        [$workspace, $user, $root] = $this->workspaceUserAndRoot();
        $sameTime = CarbonImmutable::parse('2026-09-02 12:00:00');

        for ($i = 1; $i <= 5; $i++) {
            $media = $this->media($workspace, $user, $root, 'activity-'.$i.'.pdf');
            Activity::query()->create([
                'workspace_id' => $workspace->id,
                'subject_id' => $media->id,
                'subject_type' => $media->getMorphClass(),
                'subject_uuid' => $media->uuid,
                'user_id' => $user->id,
                'type' => 'storage.media.updated',
                'description' => 'activity '.$i,
                'created_at' => $sameTime,
                'updated_at' => $sameTime,
            ]);
        }

        $queries = $this->app->make(WorkspaceFileQueryService::class);
        $first = $queries->activityCursorPayload($workspace, $user, perPage: 2);
        $second = $queries->activityCursorPayload(
            $workspace,
            $user,
            $first['pagination']['next_cursor'],
            2,
        );

        $ids = $first['activities']->pluck('id')->merge($second['activities']->pluck('id'));
        $this->assertCount(4, $ids);
        $this->assertCount(4, $ids->unique());
        $this->assertNotNull($second['pagination']['previous_cursor']);
    }

    public function test_recent_cursor_advances_media_results_without_offset_pages(): void
    {
        [$workspace, $user, $root] = $this->workspaceUserAndRoot();
        $base = CarbonImmutable::parse('2026-09-02 12:00:00');

        for ($i = 1; $i <= 3; $i++) {
            $media = $this->media($workspace, $user, $root, 'recent-'.$i.'.pdf');
            Activity::query()->create([
                'workspace_id' => $workspace->id,
                'subject_id' => $media->id,
                'subject_type' => $media->getMorphClass(),
                'subject_uuid' => $media->uuid,
                'user_id' => $user->id,
                'type' => 'storage.media.updated',
                'description' => 'recent '.$i,
                'created_at' => $base->addMinutes($i),
                'updated_at' => $base->addMinutes($i),
            ]);
        }

        $queries = $this->app->make(WorkspaceFileQueryService::class);
        $first = $queries->recentCursorPayload($workspace, $user, perPage: 2);
        $this->assertCount(2, $first['files']['data']);
        $this->assertNotNull($first['files']['pagination']['next_cursor']);

        $second = $queries->recentCursorPayload(
            $workspace,
            $user,
            fileCursor: $first['files']['pagination']['next_cursor'],
            perPage: 2,
        );
        $this->assertCount(1, $second['files']['data']);
        $this->assertFalse($second['files']['pagination']['has_more']);
    }

    /** @return array{Workspace,User,Folder} */
    private function workspaceUserAndRoot(): array
    {
        $workspace = Workspace::query()->create(['name' => 'Large Workspace']);
        $user = User::query()->create(['workspace_id' => $workspace->id, 'name' => 'User']);
        $root = Folder::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => null,
            'name' => 'Root',
            'slug' => 'root',
            'path' => 'root',
            'access_scope' => AccessScope::WORKSPACE,
            'is_root' => true,
        ]);

        return [$workspace, $user, $root];
    }

    private function folder(Workspace $workspace, User $user, Folder $root, string $name): Folder
    {
        return Folder::query()->create([
            'workspace_id' => $workspace->id,
            'parent_id' => $root->id,
            'created_by' => $user->id,
            'name' => $name,
            'slug' => $name,
            'path' => 'root/'.$name,
            'access_scope' => AccessScope::WORKSPACE,
        ]);
    }

    private function media(Workspace $workspace, User $user, Folder $root, string $name): Media
    {
        return Media::query()->create([
            'workspace_id' => $workspace->id,
            'folder_id' => $root->id,
            'disk' => Disk::PRIVATE,
            'path' => 'workspaces/'.$workspace->id.'/'.$name,
            'original_name' => $name,
            'mime_type' => 'application/pdf',
            'size' => 10,
            'use' => MediaPurpose::GENERAL,
            'current' => true,
            'uploaded_by' => $user->id,
            'access_scope' => AccessScope::WORKSPACE,
        ]);
    }
}
