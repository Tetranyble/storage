<?php

namespace Tetranyble\Storage\Modules\Access\Application\Contracts;

use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;

interface ResourceAccessControl
{
    public function grant(
        object $workspace,
        object $resource,
        object $user,
        CollaboratorRole $role,
        ?object $grantedBy = null,
    ): object;

    public function revoke(object $workspace, object $resource, object $user): void;

    public function collaboratorsFor(object $workspace, object $resource): iterable;

    public function setScope(object $workspace, object $resource, AccessScope $scope): object;

    public function effectiveRole(object $workspace, object $resource, ?object $user): ?CollaboratorRole;

    public function canView(object $workspace, object $resource, ?object $user): bool;

    public function canComment(object $workspace, object $resource, ?object $user): bool;

    public function canEdit(object $workspace, object $resource, ?object $user): bool;

    public function canManagePermissions(object $workspace, object $resource, ?object $user): bool;

    public function authorizeView(object $workspace, object $resource, ?object $user): void;

    public function authorizeEdit(object $workspace, object $resource, ?object $user): void;

    public function authorizeManagePermissions(object $workspace, object $resource, ?object $user): void;

    /**
     * Transfer ownership of a resource from $from to $to.
     * The previous owner is demoted to EDITOR so they retain access.
     */
    public function transferOwnership(object $workspace, object $resource, object $from, object $to): void;
}
