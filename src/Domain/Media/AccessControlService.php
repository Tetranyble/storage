<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Models\CollaboratorGrant;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class AccessControlService implements ResourceAccessControl
{
    public function grant(
        Model $workspace,
        Model $resource,
        Model $user,
        CollaboratorRole $role,
        ?Model $grantedBy = null,
    ): CollaboratorGrant {
        $this->assertWorkspaceResource($workspace, $resource);

        $userWorkspaceId = StorageConfig::actorWorkspaceId($user);
        if ($userWorkspaceId !== null && $userWorkspaceId !== (int) $workspace->id) {
            abort(404);
        }

        return CollaboratorGrant::updateOrCreate(
            [
                'workspace_id' => $workspace->id,
                'collaboratable_type' => $resource::class,
                'collaboratable_id' => $resource->getKey(),
                'user_id' => $user->id,
            ],
            [
                'role' => $role,
                'granted_by' => $grantedBy?->id,
            ]
        );
    }

    public function revoke(Model $workspace, Model $resource, Model $user): void
    {
        $this->assertWorkspaceResource($workspace, $resource);

        CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('collaboratable_type', $resource::class)
            ->where('collaboratable_id', $resource->getKey())
            ->where('user_id', $user->id)
            ->delete();
    }

    public function collaboratorsFor(Model $workspace, Model $resource): Collection
    {
        $this->assertWorkspaceResource($workspace, $resource);

        return CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('collaboratable_type', $resource::class)
            ->where('collaboratable_id', $resource->getKey())
            ->orderByDesc('role')
            ->orderBy('user_id')
            ->get();
    }

    public function setScope(Model $workspace, Model $resource, AccessScope $scope): Model
    {
        $this->assertWorkspaceResource($workspace, $resource);

        $resource->forceFill([
            'access_scope' => $scope,
        ])->save();

        return $resource->refresh();
    }

    public function effectiveRole(Model $workspace, Model $resource, ?Model $user): ?CollaboratorRole
    {
        $this->assertWorkspaceResource($workspace, $resource);

        if (! $user) {
            return null;
        }

        $userWorkspaceId = StorageConfig::actorWorkspaceId($user);
        if ($userWorkspaceId !== null && $userWorkspaceId !== (int) $workspace->id) {
            return null;
        }

        $role = null;

        if ($this->isOwner($resource, $user)) {
            $role = CollaboratorRole::OWNER;
        }

        $role = CollaboratorRole::highest($role, $this->explicitRoleFor($workspace, $resource, $user));

        if ($resource instanceof Media && $resource->folder) {
            $role = CollaboratorRole::highest($role, $this->folderRoleChain($workspace, $resource->folder, $user));
        }

        $scope = $this->resolveScope($resource);
        if ($scope === AccessScope::WORKSPACE
            && ! $this->hasRestrictedBoundary($resource)
            && $userWorkspaceId === (int) $workspace->id) {
            $role = CollaboratorRole::highest($role, CollaboratorRole::EDITOR);
        }

        return $role;
    }

    public function canView(Model $workspace, Model $resource, ?Model $user): bool
    {
        return $this->effectiveRole($workspace, $resource, $user)?->allowsView() ?? false;
    }

    public function canComment(Model $workspace, Model $resource, ?Model $user): bool
    {
        return $this->effectiveRole($workspace, $resource, $user)?->allowsComment() ?? false;
    }

    public function canEdit(Model $workspace, Model $resource, ?Model $user): bool
    {
        return $this->effectiveRole($workspace, $resource, $user)?->allowsEdit() ?? false;
    }

    public function canManagePermissions(Model $workspace, Model $resource, ?Model $user): bool
    {
        return $this->effectiveRole($workspace, $resource, $user)?->allowsManagePermissions() ?? false;
    }

    public function authorizeView(Model $workspace, Model $resource, ?Model $user): void
    {
        if (! $this->canView($workspace, $resource, $user)) {
            abort(403);
        }
    }

    public function authorizeEdit(Model $workspace, Model $resource, ?Model $user): void
    {
        if (! $this->canEdit($workspace, $resource, $user)) {
            abort(403);
        }
    }

    public function authorizeManagePermissions(Model $workspace, Model $resource, ?Model $user): void
    {
        if (! $this->canManagePermissions($workspace, $resource, $user)) {
            abort(403);
        }
    }

    public function transferOwnership(Model $workspace, Model $resource, Model $from, Model $to): void
    {
        $this->assertWorkspaceResource($workspace, $resource);

        if (! $this->isOwner($resource, $from)) {
            abort(403);
        }

        if ((int) $from->id === (int) $to->id) {
            return;
        }

        // Update the owner field on the resource
        if ($resource instanceof Media) {
            $resource->forceFill(['uploaded_by' => $to->id])->save();
        } elseif ($resource instanceof Folder) {
            $resource->forceFill(['created_by' => $to->id])->save();
        }

        // Demote previous owner to EDITOR (retain access, Google Drive behaviour)
        $this->grant($workspace, $resource, $from, CollaboratorRole::EDITOR);

        // Remove any explicit grant for the new owner (they are now owner by the field)
        $this->revoke($workspace, $resource, $to);
    }

    private function explicitRoleFor(Model $workspace, Model $resource, Model $user): ?CollaboratorRole
    {
        $role = CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('collaboratable_type', $resource::class)
            ->where('collaboratable_id', $resource->getKey())
            ->where('user_id', $user->id)
            ->value('role');

        if ($role instanceof CollaboratorRole) {
            return $role;
        }

        return is_string($role) ? CollaboratorRole::tryFrom($role) : null;
    }

    private function folderRoleChain(Model $workspace, Folder $folder, Model $user): ?CollaboratorRole
    {
        $role = null;
        $cursor = $folder;
        $workspaceFallbackBlocked = false;

        while ($cursor) {
            $role = CollaboratorRole::highest($role, $this->explicitRoleFor($workspace, $cursor, $user));

            if ($this->isOwner($cursor, $user)) {
                $role = CollaboratorRole::highest($role, CollaboratorRole::OWNER);
            }

            $scope = $this->resolveScope($cursor);
            if ($scope === AccessScope::RESTRICTED) {
                $workspaceFallbackBlocked = true;
            }

            if (! $workspaceFallbackBlocked
                && $scope === AccessScope::WORKSPACE
                && StorageConfig::actorWorkspaceId($user) === (int) $workspace->id) {
                $role = CollaboratorRole::highest($role, CollaboratorRole::EDITOR);
            }

            $cursor = $cursor->parent;
        }

        return $role;
    }

    private function isOwner(Model $resource, Model $user): bool
    {
        if ($resource instanceof Folder) {
            return (int) ($resource->created_by ?? 0) === (int) $user->id;
        }

        if ($resource instanceof Media) {
            return (int) ($resource->uploaded_by ?? 0) === (int) $user->id;
        }

        return false;
    }

    private function resolveScope(Model $resource): AccessScope
    {
        $scope = $resource->access_scope;

        if ($scope instanceof AccessScope) {
            return $scope;
        }

        if (is_string($scope) && $resolved = AccessScope::tryFrom($scope)) {
            return $resolved;
        }

        return AccessScope::default();
    }

    private function hasRestrictedBoundary(Model $resource): bool
    {
        if ($this->resolveScope($resource) === AccessScope::RESTRICTED) {
            return true;
        }

        if ($resource instanceof Media && $resource->folder) {
            return $this->folderHasRestrictedBoundary($resource->folder);
        }

        if ($resource instanceof Folder) {
            return $this->folderHasRestrictedBoundary($resource);
        }

        return false;
    }

    private function folderHasRestrictedBoundary(Folder $folder): bool
    {
        $cursor = $folder;

        while ($cursor) {
            if ($this->resolveScope($cursor) === AccessScope::RESTRICTED) {
                return true;
            }

            $cursor = $cursor->parent;
        }

        return false;
    }

    private function assertWorkspaceResource(Model $workspace, Model $resource): void
    {
        if ((int) ($resource->workspace_id ?? 0) !== (int) $workspace->id) {
            abort(404);
        }
    }
}
