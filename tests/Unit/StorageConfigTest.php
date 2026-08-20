<?php

namespace Tetranyble\Storage\Tests\Unit;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tetranyble\Storage\Concerns\BelongsToStorageWorkspace;
use Tetranyble\Storage\Contracts\WorkspaceSubject;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Support\StorageConfig;
use Tetranyble\Storage\Tests\PackageTestCase;
use Tetranyble\Storage\Workspace\AuthenticatedWorkspace;

class StorageConfigTest extends PackageTestCase
{
    public function test_authenticated_workspace_resolver_honors_custom_model_and_relation_configuration(): void
    {
        config()->set('tetranyble-storage.models.user', HostUserWithStorageWorkspace::class);
        config()->set('tetranyble-storage.models.workspace', HostWorkspaceModel::class);
        config()->set('tetranyble-storage.workspace.workspace_relation', 'storageWorkspace');
        config()->set('tetranyble-storage.workspace.workspace_foreign_key', 'organisation_id');

        $workspace = HostWorkspaceModel::create([
            'uuid' => Str::uuid(),
            'name' => 'Acme',
        ]);

        $actor = new HostUserWithStorageWorkspace([
            'uuid' => Str::uuid(),
            'name' => 'Alice',
            'organisation_id' => $workspace->id,
        ]);
        $actor->setRelation('storageWorkspace', $workspace);

        $request = Request::create('/storage');
        $request->setUserResolver(fn () => $actor);

        $resolver = app(AuthenticatedWorkspace::class);

        $this->assertInstanceOf(HostWorkspaceModel::class, $resolver->currentWorkspace($request));
        $this->assertSame($workspace->id, StorageConfig::actorWorkspaceId($actor));
        $this->assertSame($workspace->id, MediaUploadOptions::forModel($actor)->workspaceId);
    }

    public function test_table_overrides_are_read_from_configuration(): void
    {
        config()->set('tetranyble-storage.database.tables.users', 'members');
        config()->set('tetranyble-storage.database.tables.workspaces', 'organisations');

        $this->assertSame('members', StorageConfig::usersTable());
        $this->assertSame('organisations', StorageConfig::workspacesTable());
    }

    public function test_workspace_subject_interface_resolves_workspace_without_relation_config(): void
    {
        $workspace = HostWorkspaceModel::create([
            'uuid' => Str::uuid(),
            'name' => 'Interface Org',
        ]);

        $actor = new HostUserWithWorkspaceSubject([
            'uuid' => Str::uuid(),
            'name' => 'Interface User',
            'organisation_id' => $workspace->id,
        ]);
        $actor->setRelation('company', $workspace);

        $request = Request::create('/storage');
        $request->setUserResolver(fn () => $actor);

        $resolver = app(AuthenticatedWorkspace::class);

        $this->assertSame($workspace->id, $resolver->currentWorkspace($request)?->getKey());
        $this->assertSame($workspace->id, StorageConfig::actorWorkspaceId($actor));
        $this->assertSame($workspace->id, MediaUploadOptions::forModel($actor)->workspaceId);
    }
}

class HostWorkspaceModel extends \Tetranyble\Storage\Models\Workspace
{
    protected $table = 'workspaces';
}

class HostUserWithStorageWorkspace extends Authenticatable
{
    use BelongsToStorageWorkspace;

    protected $table = 'users';

    protected $guarded = [];
}

class HostUserWithWorkspaceSubject extends Authenticatable implements WorkspaceSubject
{
    protected $table = 'users';

    protected $guarded = [];

    public function getStorageWorkspace(): ?\Illuminate\Database\Eloquent\Model
    {
        $workspace = $this->getRelationValue('company');

        return $workspace instanceof \Illuminate\Database\Eloquent\Model ? $workspace : null;
    }

    public function getStorageWorkspaceIdentifier(): int|string|null
    {
        return $this->organisation_id;
    }
}
