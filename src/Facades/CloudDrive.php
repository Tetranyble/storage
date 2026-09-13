<?php

namespace Tetranyble\Storage\Facades;

use Illuminate\Support\Facades\Facade;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\CloudFile;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\TransferResult;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\ConnectedDriveService;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace;

/**
 * @method static ConnectedDrive connectOAuth(Workspace $workspace, CloudProvider $provider, array $tokenData, string $name)
 * @method static ConnectedDrive connectCredentials(Workspace $workspace, CloudProvider $provider, array $credentials, string $name)
 * @method static ConnectedDrive connectS3(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive connectAzureBlob(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive connectGcs(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive connectCloudinary(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive connectLocal(Workspace $workspace, string $diskName, string $name)
 * @method static void disconnect(Workspace $workspace, ConnectedDrive $drive)
 * @method static ConnectedDrive setDefault(Workspace $workspace, ConnectedDrive $drive)
 * @method static CloudAdapter adapterFor(ConnectedDrive $drive)
 * @method static array listFolder(ConnectedDrive $drive, string $folderId = 'root')
 * @method static CloudFile uploadFile(ConnectedDrive $drive, string $folderId, string $name, string $binary, string $mimeType)
 * @method static string downloadFile(ConnectedDrive $drive, string $fileId)
 * @method static CloudFile copyFile(ConnectedDrive $source, string $fileId, ConnectedDrive $destination, string $destinationFolderId, string $newName)
 * @method static TransferResult moveFile(ConnectedDrive $source, string $fileId, ConnectedDrive $destination, string $destinationFolderId, string $newName)
 * @method static void deleteFile(ConnectedDrive $drive, string $fileId)
 * @method static CloudFile createFolder(ConnectedDrive $drive, string $parentId, string $name)
 * @method static CloudFile getMetadata(ConnectedDrive $drive, string $fileId)
 *
 * @see ConnectedDriveService
 */
class CloudDrive extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ConnectedDriveService::class;
    }
}
