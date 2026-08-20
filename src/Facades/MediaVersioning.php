<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\Workspace;
use Tetranyble\Storage\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Collection versions(Media $media)
 * @method static Media|null  currentVersion(Media $media)
 * @method static Collection  activity(Media $media)
 * @method static void        deleteVersion(Workspace $workspace, Media $version, User $actor)
 * @method static string      ensureVersionSeed(Media $media)
 * @method static array       prepareContext(?Media $replacedMedia)
 * @method static void        applyContext(Media $media, array $context, bool $isCurrent = true)
 *
 * @see MediaVersioningService
 */
class MediaVersioning extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaVersioningService::class;
    }
}
