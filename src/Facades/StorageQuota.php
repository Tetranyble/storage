<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\FileSystem\DTO\StorageUsage;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Models\Workspace;
use Illuminate\Support\Facades\Facade;

/**
 * @method static StorageUsage usage(Workspace $workspace)
 * @method static void         assertCanStore(Workspace $workspace, int $bytes)
 * @method static void         increaseUsage(Workspace $workspace, int $bytes)
 * @method static void         decreaseUsage(Workspace $workspace, int $bytes)
 * @method static void         recalculateUsage(Workspace $workspace)
 *
 * @see StorageService
 */
class StorageQuota extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StorageService::class;
    }
}
