<?php

namespace Tetranyble\Storage\Tests\Benchmark;

use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Tests\PackageTestCase;
use Tetranyble\Storage\Tests\Support\LargeWorkspaceFixture;
use Tetranyble\Storage\Tests\Support\QueryPlanInspector;

class LargeWorkspaceQueryBenchmarkTest extends PackageTestCase
{
    public function test_large_workspace_search_query_count_and_plan_fixture(): void
    {
        if (! filter_var(getenv('STORAGE_RUN_QUERY_BENCHMARKS') ?: false, FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('Set STORAGE_RUN_QUERY_BENCHMARKS=1 to run the large-workspace benchmark fixture.');
        }

        $fixture = (new LargeWorkspaceFixture())->seed();
        $workspace = $fixture['workspace'];
        $viewer = $fixture['viewer'];

        DB::flushQueryLog();
        DB::enableQueryLog();
        $start = hrtime(true);
        $memory = memory_get_usage(true);

        $payload = $this->app->make(WorkspaceFileQueryService::class)->searchCursorPayload(
            $workspace,
            'benchmark-report',
            $viewer,
            perPage: 50,
            sortBy: 'updated_at',
            sortDir: 'desc',
        );

        $elapsedMs = (hrtime(true) - $start) / 1_000_000;
        $memoryDelta = max(0, memory_get_usage(true) - $memory);
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(50, $payload['files']['data']);
        $this->assertLessThanOrEqual(
            max(1, (int) config('tetranyble-storage.queries.benchmark.max_queries', 40)),
            $queryCount,
            'Large-workspace query count exceeded the configured benchmark budget.',
        );

        $visibility = $this->app->make(ResourceVisibilityQuery::class);
        $representative = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->where('original_name', 'like', '%benchmark-report%')
            ->orderByDesc('updated_at')
            ->orderByDesc('id');
        $visibility->media($representative, $workspace, $viewer);
        $plan = (new QueryPlanInspector())->explain($representative);

        fwrite(STDERR, sprintf(
            "\n[storage benchmark] driver=%s queries=%d elapsed_ms=%.2f memory_delta_mb=%.2f plan=%s\n",
            DB::connection()->getDriverName(),
            $queryCount,
            $elapsedMs,
            $memoryDelta / 1024 / 1024,
            json_encode($plan, JSON_UNESCAPED_SLASHES),
        ));

        $this->assertNotEmpty($plan);
    }
}
