<?php

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AuthenticationRequiredException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext as WorkspaceContract;
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
        if ($workspace === null) {
            if ($this->currentActor($request)) {
                throw new AccessDeniedException('The current actor is not associated with a storage workspace.');
            }

            throw new AuthenticationRequiredException();
        }

        return $workspace;
    }

    public function owns(Model $workspace, Model $resource): bool
    {
        $ownerKey = (string) config('tetranyble-storage.workspace.resource_foreign_key', 'workspace_id');

        return (string) $resource->getAttribute($ownerKey) === (string) $workspace->getKey();
    }

    public function authorizeOwnership(Model $workspace, Model $resource): void
    {
        if (! $this->owns($workspace, $resource)) {
            throw new ResourceNotFoundException();
        }
    }
}
