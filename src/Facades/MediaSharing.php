<?php

namespace Tetranyble\Storage\Facades;

use Illuminate\Support\Facades\Facade;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Application\Contracts\MediaShares;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Application\MediaShareService;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;

/**
 * @method static MediaShare createForMedia(Workspace $workspace, Media $media, string $accessLevel = 'download', ?int $ttlMinutes = null, ?int $maxDownloads = null, ?string $password = null, ?int $createdBy = null)
 * @method static MediaShare createForFolder(Workspace $workspace, Folder $folder, string $accessLevel = 'view', ?int $ttlMinutes = null, ?int $maxDownloads = null, ?string $password = null, ?int $createdBy = null)
 * @method static MediaShare|null resolveByToken(string $token)
 * @method static void validateAccess(MediaShare $share, ?string $password = null)
 * @method static void validateDownloadAccess(MediaShare $share, ?string $password = null)
 * @method static void consumeDownloadAccess(MediaShare $share, ?string $password = null)
 * @method static string urlFor(MediaShare $share, bool $absolute = true)
 *
 * @see MediaShareService
 */
class MediaSharing extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaShares::class;
    }
}
