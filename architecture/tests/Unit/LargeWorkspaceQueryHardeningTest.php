<?php

namespace Tetranyble\Storage\Tests\Unit;

use PHPUnit\Framework\TestCase;

class LargeWorkspaceQueryHardeningTest extends TestCase
{
    public function test_cursor_pagination_runtime_dependency_is_explicit(): void
    {
        $composer = json_decode(
            (string) file_get_contents(__DIR__.'/../../composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $this->assertSame('^12.0|^13.0', $composer['require']['illuminate/pagination'] ?? null);
        $this->assertSame('@php scripts/run-query-benchmark.php', $composer['scripts']['benchmark:queries'] ?? null);
    }

    public function test_large_workspace_routes_use_cursor_query_surfaces(): void
    {
        $routes = (string) file_get_contents(__DIR__.'/../../routes/storage.php');

        $this->assertStringContainsString("Route::get('search'", $routes);
        $this->assertStringContainsString("Route::get('recent'", $routes);
        $this->assertStringContainsString("Route::get('activity'", $routes);
    }

    public function test_query_service_contains_cursor_paths_and_bounded_offset_folder_search(): void
    {
        $compat = (string) file_get_contents(__DIR__.'/../../src/Modules/Workspace/Infrastructure/Queries/WorkspaceFileQueryService.php');
        $search = (string) file_get_contents(__DIR__.'/../../src/Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers/SearchWorkspaceHandler.php');
        $recent = (string) file_get_contents(__DIR__.'/../../src/Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers/RecentWorkspaceHandler.php');
        $activity = (string) file_get_contents(__DIR__.'/../../src/Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers/ActivityWorkspaceHandler.php');

        $this->assertStringContainsString('searchCursorPayload', $compat);
        $this->assertStringContainsString('recentCursorPayload', $compat);
        $this->assertStringContainsString('activityCursorPayload', $compat);
        $this->assertStringContainsString('cursorPaginate(', $search);
        $this->assertStringContainsString('cursorPaginate(', $recent);
        $this->assertStringContainsString('cursorPaginate(', $activity);
    }

    public function test_fresh_schema_contains_cursor_access_paths(): void
    {
        $folders = (string) file_get_contents(__DIR__.'/../../database/migrations/2026_06_06_000001_create_folders_table.php');
        $media = (string) file_get_contents(__DIR__.'/../../database/migrations/2026_06_06_000002_create_media_table.php');
        $activities = (string) file_get_contents(__DIR__.'/../../database/migrations/activities/2026_06_06_000005_create_activities_table.php');

        $this->assertStringContainsString('folders_workspace_name_cursor_idx', $folders);
        $this->assertStringContainsString('media_workspace_updated_cursor_idx', $media);
        $this->assertStringContainsString('media_workspace_created_cursor_idx', $media);
        $this->assertStringContainsString('activities_workspace_type_cursor_idx', $activities);
    }

    public function test_query_benchmark_fixture_is_opt_in_and_cross_database(): void
    {
        $benchmark = (string) file_get_contents(__DIR__.'/../Benchmark/LargeWorkspaceQueryBenchmarkTest.php');
        $inspector = (string) file_get_contents(__DIR__.'/../Support/QueryPlanInspector.php');

        $this->assertStringContainsString('STORAGE_RUN_QUERY_BENCHMARKS', $benchmark);
        $this->assertStringContainsString("'pgsql' => 'EXPLAIN (FORMAT JSON) '", $inspector);
        $this->assertStringContainsString("'mysql', 'mariadb' => 'EXPLAIN FORMAT=JSON '", $inspector);
        $this->assertStringContainsString("'EXPLAIN QUERY PLAN '", $inspector);
    }
}
