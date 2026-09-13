<?php

namespace Tetranyble\Storage\Facades;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\MediaVersioning as MediaVersioningPort;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;

/**
 * @method static Collection versions(Media $media)
 * @method static Media|null currentVersion(Media $media)
 * @method static Collection activity(Media $media)
 * @method static void deleteVersion(Workspace $workspace, Media $version, User $actor)
 * @method static string ensureVersionSeed(Media $media)
 * @method static array prepareContext(?Media $replacedMedia)
 * @method static void applyContext(Media $media, array $context, bool $isCurrent = true)
 *
 * @see MediaVersioningService
 */
class MediaVersioning extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaVersioningPort::class;
    }
}
