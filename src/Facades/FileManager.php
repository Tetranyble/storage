<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array           indexPayload(Workspace $workspace, string $relativePath, User $actor, ?Folder $folder = null, int $perPage = 20)
 * @method static Folder          createFolder(Workspace $workspace, string $name, User $actor, ?Folder $parent = null)
 * @method static Folder          renameFolder(Workspace $workspace, Folder $folder, string $newName, User $actor)
 * @method static void            trashFolder(Workspace $workspace, Folder $folder, User $actor)
 * @method static Media           uploadFile(Workspace $workspace, UploadedFile $file, Folder $folder, User $actor, ?Disk $disk = null)
 * @method static Media           star(Workspace $workspace, Media $media, User $actor)
 * @method static Media           unstar(Workspace $workspace, Media $media, User $actor)
 * @method static void            trashMedia(Workspace $workspace, Media $media, User $actor)
 * @method static void            restoreMedia(Workspace $workspace, Media $media, User $actor)
 * @method static void            permanentlyDeleteMedia(Workspace $workspace, Media $media, User $actor)
 * @method static Media           createRevision(Workspace $workspace, Media $media, UploadedFile $file, User $actor)
 * @method static Media           restoreVersion(Workspace $workspace, Media $media, Media $revision, User $actor)
 *
 * @see WorkspaceFileManagerService
 */
class FileManager extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return WorkspaceFileManagerService::class;
    }
}
