<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Access\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Access\AccessControlService;

final class EloquentResourceAccessControl implements ResourceAccessControl
{
    public function __construct(private readonly AccessControlService $access) {}

    public function grant(
        object $workspace,
        object $resource,
        object $user,
        CollaboratorRole $role,
        ?object $grantedBy = null,
    ): object {
        return $this->access->grant(
            $this->model($workspace),
            $this->model($resource),
            $this->model($user),
            $role,
            $this->nullableModel($grantedBy),
        );
    }

    public function revoke(object $workspace, object $resource, object $user): void
    {
        $this->access->revoke($this->model($workspace), $this->model($resource), $this->model($user));
    }

    public function collaboratorsFor(object $workspace, object $resource): iterable
    {
        return $this->access->collaboratorsFor($this->model($workspace), $this->model($resource));
    }

    public function setScope(object $workspace, object $resource, AccessScope $scope): object
    {
        return $this->access->setScope($this->model($workspace), $this->model($resource), $scope);
    }

    public function effectiveRole(object $workspace, object $resource, ?object $user): ?CollaboratorRole
    {
        return $this->access->effectiveRole($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function canView(object $workspace, object $resource, ?object $user): bool
    {
        return $this->access->canView($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function canComment(object $workspace, object $resource, ?object $user): bool
    {
        return $this->access->canComment($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function canEdit(object $workspace, object $resource, ?object $user): bool
    {
        return $this->access->canEdit($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function canManagePermissions(object $workspace, object $resource, ?object $user): bool
    {
        return $this->access->canManagePermissions($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function authorizeView(object $workspace, object $resource, ?object $user): void
    {
        $this->access->authorizeView($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function authorizeEdit(object $workspace, object $resource, ?object $user): void
    {
        $this->access->authorizeEdit($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function authorizeManagePermissions(object $workspace, object $resource, ?object $user): void
    {
        $this->access->authorizeManagePermissions($this->model($workspace), $this->model($resource), $this->nullableModel($user));
    }

    public function transferOwnership(object $workspace, object $resource, object $from, object $to): void
    {
        $this->access->transferOwnership(
            $this->model($workspace),
            $this->model($resource),
            $this->model($from),
            $this->model($to),
        );
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected an Eloquent model resource.');
        }

        return $value;
    }

    private function nullableModel(?object $value): ?Model
    {
        return $value === null ? null : $this->model($value);
    }
}
