<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
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
 * @see \Tetranyble\Storage\Domain\Media\AccessControlService
 */
class MediaAccess extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResourceAccessControl::class;
    }
}
