<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\Media\MediaShareService;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\Workspace;
use Illuminate\Support\Facades\Facade;

/**
 * @method static MediaShare      createForMedia(Workspace $workspace, Media $media, string $accessLevel = 'download', ?int $ttlMinutes = null, ?int $maxDownloads = null, ?string $password = null, ?int $createdBy = null)
 * @method static MediaShare      createForFolder(Workspace $workspace, Folder $folder, string $accessLevel = 'view', ?int $ttlMinutes = null, ?int $maxDownloads = null, ?string $password = null, ?int $createdBy = null)
 * @method static MediaShare|null resolveByToken(string $token)
 * @method static void            validateAccess(MediaShare $share, ?string $password = null)
 * @method static void            validateDownloadAccess(MediaShare $share, ?string $password = null)
 * @method static void            consumeDownloadAccess(MediaShare $share, ?string $password = null)
 * @method static string          urlFor(MediaShare $share, bool $absolute = true)
 *
 * @see MediaShareService
 */
class MediaSharing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaShareService::class;
    }
}
