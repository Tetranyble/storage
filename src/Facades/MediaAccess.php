<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\CollaboratorRole;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void grant(Workspace $workspace, Media|Folder $resource, User $user, CollaboratorRole $role, ?User $grantedBy = null)
 * @method static void revoke(Workspace $workspace, Media|Folder $resource, User $user)
 * @method static bool canView(Workspace $workspace, Media|Folder $resource, User $user)
 * @method static bool canEdit(Workspace $workspace, Media|Folder $resource, User $user)
 * @method static void authorizeView(Workspace $workspace, Media|Folder $resource, User $user)
 * @method static void authorizeEdit(Workspace $workspace, Media|Folder $resource, User $user)
 * @method static void transferOwnership(Workspace $workspace, Media|Folder $resource, User $newOwner, User $actor)
 *
 * @see \Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Access\AccessControlService
 */
class MediaAccess extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResourceAccessControl::class;
    }
}
