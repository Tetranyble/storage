<?php

namespace Tetranyble\Storage\Workspace;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tetranyble\Storage\Contracts\Workspace as WorkspaceContract;
use Tetranyble\Storage\Support\StorageConfig;

class AuthenticatedWorkspace implements WorkspaceContract
{
    public function currentWorkspace(Request $request): ?Model
    {
        $actor = $this->currentActor($request);
        if (! $actor) {
            return null;
        }

        return StorageConfig::resolveWorkspaceFromModel($actor);
    }

    public function currentActor(Request $request): ?Model
    {
        $guard = config('tetranyble-storage.workspace.guard');
        $actor = $request->user(is_string($guard) && $guard !== '' ? $guard : null);

        return $actor instanceof Model ? $actor : null;
    }

    public function requireWorkspace(Request $request): Model
    {
        $workspace = $this->currentWorkspace($request);
        abort_if($workspace === null, $this->currentActor($request) ? 403 : 401);

        return $workspace;
    }

    public function owns(Model $workspace, Model $resource): bool
    {
        $ownerKey = (string) config('tetranyble-storage.workspace.resource_foreign_key', 'workspace_id');

        return (string) $resource->getAttribute($ownerKey) === (string) $workspace->getKey();
    }

    public function authorizeOwnership(Model $workspace, Model $resource): void
    {
        abort_unless($this->owns($workspace, $resource), 404);
    }
}
