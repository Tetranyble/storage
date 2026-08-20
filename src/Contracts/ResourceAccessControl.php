<?php

namespace Tetranyble\Storage\Contracts;

use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Models\CollaboratorGrant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

interface ResourceAccessControl
{
    public function grant(
        Model $workspace,
        Model $resource,
        Model $user,
        CollaboratorRole $role,
        ?Model $grantedBy = null,
    ): CollaboratorGrant;

    public function revoke(Model $workspace, Model $resource, Model $user): void;

    public function collaboratorsFor(Model $workspace, Model $resource): Collection;

    public function setScope(Model $workspace, Model $resource, AccessScope $scope): Model;

    public function effectiveRole(Model $workspace, Model $resource, ?Model $user): ?CollaboratorRole;

    public function canView(Model $workspace, Model $resource, ?Model $user): bool;

    public function canComment(Model $workspace, Model $resource, ?Model $user): bool;

    public function canEdit(Model $workspace, Model $resource, ?Model $user): bool;

    public function canManagePermissions(Model $workspace, Model $resource, ?Model $user): bool;

    public function authorizeView(Model $workspace, Model $resource, ?Model $user): void;

    public function authorizeEdit(Model $workspace, Model $resource, ?Model $user): void;

    public function authorizeManagePermissions(Model $workspace, Model $resource, ?Model $user): void;

    /**
     * Transfer ownership of a resource from $from to $to.
     * The previous owner is demoted to EDITOR so they retain access.
     */
    public function transferOwnership(Model $workspace, Model $resource, Model $from, Model $to): void;
}
