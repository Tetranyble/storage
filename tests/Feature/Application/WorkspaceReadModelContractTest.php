<?php

namespace Tetranyble\Storage\Tests\Feature\Application;

use Tetranyble\Storage\Modules\Workspace\Application\Contracts\WorkspaceReadModel;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\SearchWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Queries\WorkspaceFileQueryService;
use Tetranyble\Storage\Tests\PackageTestCase;

class WorkspaceReadModelContractTest extends PackageTestCase
{
    public function test_application_read_port_resolves_to_the_eloquent_cqrs_adapter(): void
    {
        $readModel = $this->app->make(WorkspaceReadModel::class);

        $this->assertInstanceOf(WorkspaceFileQueryService::class, $readModel);
    }

    public function test_read_port_accepts_framework_neutral_query_objects(): void
    {
        $workspace = Workspace::query()->create(['name' => 'CQRS Workspace']);

        $payload = $this->app->make(WorkspaceReadModel::class)->search(
            new SearchWorkspace($workspace, 'nothing'),
        );

        $this->assertSame('nothing', $payload['query']);
        $this->assertArrayHasKey('folders', $payload);
        $this->assertArrayHasKey('files', $payload);
    }
}
