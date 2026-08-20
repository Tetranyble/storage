<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Domain\CloudDrive\DTO\TransferResult;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Workspace;
use Illuminate\Support\Facades\Facade;

/**
 * @method static ConnectedDrive   connectOAuth(Workspace $workspace, CloudProvider $provider, array $tokenData, string $name)
 * @method static ConnectedDrive   connectS3(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive   connectAzureBlob(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive   connectGcs(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive   connectCloudinary(Workspace $workspace, array $credentials, string $name)
 * @method static ConnectedDrive   connectLocal(Workspace $workspace, string $diskName, string $name)
 * @method static void             disconnect(Workspace $workspace, ConnectedDrive $drive)
 * @method static ConnectedDrive   setDefault(Workspace $workspace, ConnectedDrive $drive)
 * @method static CloudAdapter     adapterFor(ConnectedDrive $drive)
 * @method static array            listFolder(ConnectedDrive $drive, string $folderId = 'root')
 * @method static CloudFile        uploadFile(ConnectedDrive $drive, string $folderId, string $name, string $binary, string $mimeType)
 * @method static string           downloadFile(ConnectedDrive $drive, string $fileId)
 * @method static CloudFile        copyFile(ConnectedDrive $source, string $fileId, ConnectedDrive $destination, string $destinationFolderId, string $newName)
 * @method static TransferResult   moveFile(ConnectedDrive $source, string $fileId, ConnectedDrive $destination, string $destinationFolderId, string $newName)
 * @method static void             deleteFile(ConnectedDrive $drive, string $fileId)
 * @method static CloudFile        createFolder(ConnectedDrive $drive, string $parentId, string $name)
 * @method static CloudFile        getMetadata(ConnectedDrive $drive, string $fileId)
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
