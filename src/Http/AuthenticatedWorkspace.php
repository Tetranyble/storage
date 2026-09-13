<?php

namespace Tetranyble\Storage\Http;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Tetranyble\Storage\Http\Contracts\WorkspaceContext;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AccessDeniedException;
use Tetranyble\Storage\Modules\Access\Domain\Exceptions\AuthenticationRequiredException;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Support\StorageConfig;

class AuthenticatedWorkspace implements WorkspaceContext
{
    public function currentWorkspace(Request $request): ?Model
    {
        $actor = $this->currentActor($request);

        return $actor ? StorageConfig::resolveWorkspaceFromModel($actor) : null;
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
        if ($workspace !== null) {
            return $workspace;
        }

        if ($this->currentActor($request)) {
            throw new AccessDeniedException('The current actor is not associated with a storage workspace.');
        }

        throw new AuthenticationRequiredException;
    }

    public function owns(object $workspace, object $resource): bool
    {
        if (! $workspace instanceof Model || ! $resource instanceof Model) {
            return false;
        }

        $ownerKey = (string) config('tetranyble-storage.workspace.resource_foreign_key', 'workspace_id');

        return (string) $resource->getAttribute($ownerKey) === (string) $workspace->getKey();
    }

    public function authorizeOwnership(object $workspace, object $resource): void
    {
        if (! $this->owns($workspace, $resource)) {
            throw new ResourceNotFoundException;
        }
    }
}
