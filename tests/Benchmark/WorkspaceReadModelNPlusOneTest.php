<?php

namespace Tetranyble\Storage\Tests\Benchmark;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Tests\PackageTestCase;
use Tetranyble\Storage\Tests\Support\LargeWorkspaceFixture;

final class WorkspaceReadModelNPlusOneTest extends PackageTestCase
{
    public function test_primary_workspace_reads_do_not_trigger_lazy_loading_or_unbounded_query_growth(): void
    {
        if (! filter_var(getenv('STORAGE_RUN_QUERY_BENCHMARKS') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set STORAGE_RUN_QUERY_BENCHMARKS=1 to run read-model N+1 checks.');
        }

        $fixture = (new LargeWorkspaceFixture())->seed();
        $workspace = $fixture['workspace'];
        $viewer = $fixture['viewer'];
        $queries = $this->app->make(WorkspaceFileQueryService::class);
        $budget = max(1, (int) config('tetranyble-storage.queries.benchmark.max_queries', 40));

        Model::preventLazyLoading(true);
        try {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $browse = $queries->indexPayload($workspace, actor: $viewer, perPage: 50);
            $browseQueries = count(DB::getQueryLog());

            DB::flushQueryLog();
            $search = $queries->searchCursorPayload(
                $workspace,
                'benchmark-report',
                $viewer,
                perPage: 50,
            );
            $searchQueries = count(DB::getQueryLog());
            DB::disableQueryLog();

            $this->assertNotEmpty($browse['folders']);
            $this->assertCount(50, $browse['files']);
            $this->assertCount(50, $search['files']['data']);
            $this->assertLessThanOrEqual($budget, $browseQueries, 'Browse query count exceeded the release budget.');
            $this->assertLessThanOrEqual($budget, $searchQueries, 'Search query count exceeded the release budget.');
        } finally {
            DB::disableQueryLog();
            Model::preventLazyLoading(false);
        }
    }
}
