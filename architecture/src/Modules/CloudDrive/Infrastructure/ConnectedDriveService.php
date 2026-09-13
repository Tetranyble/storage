<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Events\DriveConnected;
use Tetranyble\Storage\Events\DriveDisconnected;
use Tetranyble\Storage\Modules\Access\Application\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\CloudFile;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\TransferResult;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderRegistry;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\DefaultCloudProviderRegistryFactory;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaStatus;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Application\MediaDeliveryGuard;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;

class ConnectedDriveService
{
    private ?CloudProviderRegistry $resolvedProviders = null;

    public function __construct(
        private readonly OAuthService $oauth,
        private readonly FileSystemContract $files,
        private readonly StorageService $storage,
        private readonly ?StorageTransferAuthorizer $transferAuthorization = null,
        private readonly ?CloudProviderDependencyGuard $dependencies = null,
        private readonly ?StorageLifecycleService $lifecycle = null,
        private readonly ?MediaProcessingDispatcher $processing = null,
        private readonly ?MediaDeliveryGuard $delivery = null,
        private readonly ?QuarantineStoragePolicy $quarantineStorage = null,
        private readonly ?CloudProviderRegistry $providers = null,
        private readonly ?ConnectedDriveDefaultCoordinator $defaultCoordinator = null,
    ) {}

    // ---------------------------------------------------------------
    // Connect / Disconnect
    // ---------------------------------------------------------------

    /**
     * Finalise a Google Drive or OneDrive OAuth connection after the callback.
     * $tokenData is the array returned by OAuthService::exchangeCode().
     * The drive is automatically set as default if it is the first one for the workspace.
     */
    public function connectOAuth(
        Model $workspace,
        CloudProvider $provider,
        array $tokenData,
        string $name,
    ): ConnectedDrive {
        $this->providerRegistry()->oauthStrategy($provider);
        $this->providerRegistry()->assertAvailable($provider);

        $drive = DB::transaction(function () use ($workspace, $provider, $tokenData, $name): ConnectedDrive {
            $isFirst = $this->defaults()->claimFirstSlot($workspace);

            return ConnectedDrive::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'provider' => $provider,
                'name' => $name,
                'access_token' => $tokenData['access_token'],
                'refresh_token' => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => $tokenData['expires_at'] ?? null,
                'credentials' => [],
                'status' => ConnectedDriveStatus::CONNECTED,
                'is_default' => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Connect a local Laravel filesystem disk (e.g. 'local' or 'public').
     *
     * Both private ('local') and public ('public') disks are supported — pass
     * whichever disk name is defined in your filesystems.php config.
     * The drive is automatically set as default if it is the first one for the workspace.
     */
    public function connectLocal(
        Model $workspace,
        string $diskName,
        string $name,
    ): ConnectedDrive {
        return $this->connectCredentials($workspace, CloudProvider::LOCAL, ['disk' => $diskName], $name);
    }

    /** Connect an Azure Blob Storage container. */
    public function connectAzureBlob(Model $workspace, array $credentials, string $name): ConnectedDrive
    {
        return $this->connectCredentials($workspace, CloudProvider::AZURE_BLOB, $credentials, $name);
    }

    /** Connect a Google Cloud Storage bucket. */
    public function connectGcs(Model $workspace, array $credentials, string $name): ConnectedDrive
    {
        return $this->connectCredentials($workspace, CloudProvider::GCS, $credentials, $name);
    }

    /** Connect a Cloudinary account. */
    public function connectCloudinary(Model $workspace, array $credentials, string $name): ConnectedDrive
    {
        return $this->connectCredentials($workspace, CloudProvider::CLOUDINARY, $credentials, $name);
    }

    /** Connect an Amazon S3 bucket using static credentials. */
    public function connectS3(Model $workspace, array $credentials, string $name): ConnectedDrive
    {
        return $this->connectCredentials($workspace, CloudProvider::S3, $credentials, $name);
    }

    /**
     * Connect any registered non-OAuth provider. Provider-specific validation and
     * connection probing belong to the registered provider strategy.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function connectCredentials(
        Model $workspace,
        CloudProvider $provider,
        array $credentials,
        string $name,
    ): ConnectedDrive {
        if ($this->providerRegistry()->isOAuth($provider)) {
            throw new RuntimeException("{$provider->label()} must be connected through OAuth.");
        }

        $this->providerRegistry()->prepareCredentials($provider, $credentials);

        $drive = DB::transaction(function () use ($workspace, $provider, $credentials, $name): ConnectedDrive {
            $isFirst = $this->defaults()->claimFirstSlot($workspace);

            return ConnectedDrive::create([
                'uuid' => (string) Str::uuid(),
                'workspace_id' => $workspace->getKey(),
                'provider' => $provider,
                'name' => $name,
                'credentials' => $credentials,
                'status' => ConnectedDriveStatus::CONNECTED,
                'is_default' => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Revoke and soft-delete a connected drive.
     * If the disconnected drive was the default, the oldest remaining drive is promoted.
     */
    public function disconnect(Model $workspace, ConnectedDrive $drive, ?Model $actor = null): void
    {
        $this->assertWorkspaceDrive($workspace, $drive);

        $wasDefault = (bool) $drive->is_default;

        DB::transaction(function () use ($drive): void {
            $drive->forceFill([
                'status' => ConnectedDriveStatus::DISCONNECTED,
                'is_default' => false,
                'default_slot' => null,
            ])->save();
            $drive->delete();
        });

        if ($wasDefault) {
            $this->defaults()->promoteOldest($workspace);
        }

        Event::dispatch(new DriveDisconnected($drive, $actor));
    }

    // ---------------------------------------------------------------
    // Default drive management
    // ---------------------------------------------------------------

    /**
     * Mark $drive as the default for the workspace.
     * Atomically clears any existing default before setting the new one.
     */
    public function setDefault(Model $workspace, ConnectedDrive $drive, ?Model $actor = null): void
    {
        $this->assertWorkspaceDrive($workspace, $drive);
        $this->transferAuthorization?->authorizeSetDefaultDrive($workspace, $drive, $actor);
        $this->defaults()->setDefault($workspace, $drive);
    }

    /**
     * Get the workspace's current default drive, or null if none is connected.
     */
    public function getDefault(Model $workspace): ?ConnectedDrive
    {
        return $this->defaults()->getDefault($workspace);
    }

    /**
     * Resolve the drive to use for an operation.
     * Returns $drive if provided, otherwise falls back to the workspace default.
     * Throws if neither is available.
     */
    public function resolveDrive(Model $workspace, ?ConnectedDrive $drive = null): ConnectedDrive
    {
        if ($drive !== null) {
            $this->assertWorkspaceDrive($workspace, $drive);

            return $drive;
        }

        $default = $this->getDefault($workspace);

        if ($default === null) {
            throw new RuntimeException('No default drive configured for this workspace. Connect a drive first.');
        }

        return $default;
    }

    // ---------------------------------------------------------------
    // List
    // ---------------------------------------------------------------

    public function listConnected(Model $workspace): Collection
    {
        return ConnectedDrive::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', '!=', ConnectedDriveStatus::DISCONNECTED->value)
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    // ---------------------------------------------------------------
    // Browse
    // ---------------------------------------------------------------

    /**
     * List files/folders in a remote folder.
     * Resolves the drive from the workspace default if $drive is null.
     */
    public function browseFolder(
        Model $workspace,
        ?ConnectedDrive $drive = null,
        string $folderId = 'root',
    ): array {
        $drive = $this->resolveDrive($workspace, $drive);
        $adapter = $this->adapterFor($drive);
        $items = $adapter->listFolder($folderId);

        return [
            'drive' => $this->driveDto($drive),
            'folder' => $folderId,
            'items' => array_map(fn (CloudFile $f) => $f->toArray(), $items),
            'count' => count($items),
        ];
    }

    // ---------------------------------------------------------------
    // Copy / Move between drives (or within the same drive)
    // ---------------------------------------------------------------

    /**
     * Copy a single file from one drive to another (or within the same drive).
     *
     * When source and target are the same connected drive and the adapter supports
     * native same-drive operations, the file is copied server-side with no download.
     * Otherwise the binary is streamed from source to target.
     */
    public function copyFile(
        Model $workspace,
        ConnectedDrive $from,
        string $fileId,
        ConnectedDrive $to,
        string $targetFolderId = 'root',
        ?string $newName = null,
        ?Model $actor = null,
    ): CloudFile {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeCopy($workspace, $from, $to, $actor);

        $fromAdapter = $this->adapterFor($from);

        if ($from->id === $to->id && $fromAdapter instanceof SupportsSameDriveOperations) {
            $name = $newName ?? $fromAdapter->getMetadata($fileId)->name;

            return $fromAdapter->copyFileSameDrive($fileId, $targetFolderId, $name);
        }

        $meta = $fromAdapter->getMetadata($fileId);
        $name = $newName ?? $meta->name;
        $binary = $fromAdapter->getFileBinary($fileId);

        return $this->adapterFor($to)->putFile($targetFolderId, $name, $binary, $meta->mimeType ?? 'application/octet-stream');
    }

    /**
     * Move a single file from one drive to another (or within the same drive).
     *
     * Same-drive moves are done server-side when the adapter supports it.
     * Cross-drive moves copy then delete the source.
     */
    public function moveFile(
        Model $workspace,
        ConnectedDrive $from,
        string $fileId,
        ConnectedDrive $to,
        string $targetFolderId = 'root',
        ?string $newName = null,
        ?Model $actor = null,
    ): CloudFile {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeMove($workspace, $from, $to, $actor);

        $fromAdapter = $this->adapterFor($from);

        if ($from->id === $to->id && $fromAdapter instanceof SupportsSameDriveOperations) {
            $name = $newName ?? $fromAdapter->getMetadata($fileId)->name;

            return $fromAdapter->moveFileSameDrive($fileId, $targetFolderId, $name);
        }

        $result = $this->copyFile($workspace, $from, $fileId, $to, $targetFolderId, $newName, $actor);
        $fromAdapter->deleteFile($fileId);

        return $result;
    }

    /**
     * Recursively copy a folder from one drive to another.
     * Returns a TransferResult with counts and any per-file errors.
     */
    public function copyFolder(
        Model $workspace,
        ConnectedDrive $from,
        string $folderId,
        ConnectedDrive $to,
        string $targetParentId = 'root',
        ?string $newName = null,
        ?Model $actor = null,
    ): TransferResult {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeCopy($workspace, $from, $to, $actor);

        $fromAdapter = $this->adapterFor($from);
        $toAdapter = $this->adapterFor($to);

        $sourceMeta = $fromAdapter->getMetadata($folderId);
        $rootFolder = $toAdapter->createFolder($targetParentId, $newName ?? $sourceMeta->name);

        [$filesCopied, $foldersCreated, $errors] = $this->recursiveCopy(
            $fromAdapter, $toAdapter, $folderId, $rootFolder->id
        );

        return new TransferResult($rootFolder, $filesCopied, $foldersCreated + 1, $errors);
    }

    /**
     * Recursively move a folder from one drive to another.
     * Copies everything first; deletes the source only if the full copy succeeded.
     */
    public function moveFolder(
        Model $workspace,
        ConnectedDrive $from,
        string $folderId,
        ConnectedDrive $to,
        string $targetParentId = 'root',
        ?string $newName = null,
        ?Model $actor = null,
    ): TransferResult {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeMove($workspace, $from, $to, $actor);

        $result = $this->copyFolder($workspace, $from, $folderId, $to, $targetParentId, $newName, $actor);

        if (! $result->hasErrors()) {
            $this->adapterFor($from)->deleteFile($folderId);
        }

        return $result;
    }

    // ---------------------------------------------------------------
    // Import: remote → local Media record
    // ---------------------------------------------------------------

    public function importFile(
        Model $workspace,
        ConnectedDrive $drive,
        string $remoteFileId,
        Folder $targetFolder,
        Model $actor,
    ): Media {
        $this->assertWorkspaceDrive($workspace, $drive);
        $this->assertWorkspaceFolder($workspace, $targetFolder);

        $adapter = $this->adapterFor($drive);
        $metadata = $adapter->getMetadata($remoteFileId);

        if ($metadata->isFolder) {
            throw new RuntimeException('Cannot import a folder as a Media record. Import individual files.');
        }

        $maxBytes = max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
        if ($metadata->size !== null && $metadata->size > $maxBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Cloud-drive import exceeds the configured maximum size (%d bytes > %d bytes).',
                $metadata->size,
                $maxBytes,
            ));
        }

        $binary = $adapter->getFileBinary($remoteFileId);
        $size = strlen($binary);
        if ($size > $maxBytes) {
            throw new InvalidStorageOperationException(sprintf(
                'Cloud-drive import exceeds the configured maximum size (%d bytes > %d bytes).',
                $size,
                $maxBytes,
            ));
        }

        $disk = $this->files->getDefaultDisk();
        $this->quarantineStorage?->assertStorageSafe($disk);
        $extension = pathinfo($metadata->name, PATHINFO_EXTENSION);
        $path = $targetFolder->path.'/'.Str::uuid().($extension ? ".{$extension}" : '');

        $media = $this->lifecycle()->storeAndCommit(
            workspace: $workspace,
            disk: $disk,
            size: $size,
            store: function () use ($path, $binary, $disk): string {
                if (! $this->files->put($path, $binary, $disk)) {
                    throw new RuntimeException('Unable to persist imported cloud-drive object.');
                }

                return $path;
            },
            commit: fn (string $storedPath): Media => DB::transaction(
                fn (): Media => Media::create([
                    'workspace_id' => $workspace->getKey(),
                    'folder_id' => $targetFolder->id,
                    'uploaded_by' => $actor->getKey(),
                    'original_name' => $metadata->name,
                    'mime_type' => $metadata->mimeType ?? 'application/octet-stream',
                    'size' => $size,
                    'path' => $storedPath,
                    'disk' => $disk,
                    'status' => MediaStatus::READY,
                    'uploaded_at' => now(),
                    'current' => true,
                ]),
            ),
            rollbackReason: 'cloud_drive_import_rollback',
            expectedPath: $path,
        );

        if ((bool) config('tetranyble-storage.processing.auto_dispatch', true)
            || (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false)) {
            $this->processing?->dispatch($media);
        }

        return $media;
    }

    // ---------------------------------------------------------------
    // Export: local Media → remote drive
    // ---------------------------------------------------------------

    /**
     * Push a local Media file to an external drive.
     * Uses the workspace default if $drive is null.
     */
    public function exportFile(
        Model $workspace,
        Media $media,
        ?ConnectedDrive $drive = null,
        string $remoteFolderId = 'root',
    ): CloudFile {
        $drive = $this->resolveDrive($workspace, $drive);
        $this->assertWorkspaceMedia($workspace, $media);
        $this->delivery?->assertDeliverable($media);

        $adapter = $this->adapterFor($drive);
        $binary = $this->files->get($media->path, $media->disk);
        $mimeType = $media->mime_type ?? 'application/octet-stream';

        return $adapter->putFile($remoteFolderId, $media->original_name ?? basename($media->path), $binary, $mimeType);
    }

    // ---------------------------------------------------------------
    // Adapter factory
    // ---------------------------------------------------------------

    public function adapterFor(ConnectedDrive $drive): CloudAdapter
    {
        $this->providerRegistry()->assertAvailable($drive->provider);

        if ($this->providerRegistry()->isOAuth($drive->provider) && $drive->isTokenExpiringSoon()) {
            $drive = $this->oauth->refreshAccessToken($drive);
        }

        return $this->providerRegistry()->adapterFor($drive);
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function providerRegistry(): CloudProviderRegistry
    {
        return $this->providers
            ?? ($this->resolvedProviders ??= DefaultCloudProviderRegistryFactory::make($this->dependencyGuard()));
    }

    private function dependencyGuard(): CloudProviderDependencyGuard
    {
        return $this->dependencies ?? new CloudProviderDependencyGuard;
    }

    private function lifecycle(): StorageLifecycleService
    {
        return $this->lifecycle ?? new StorageLifecycleService(
            $this->storage,
            new StorageOrphanService($this->files),
        );
    }

    private function defaults(): ConnectedDriveDefaultCoordinator
    {
        return $this->defaultCoordinator ?? new ConnectedDriveDefaultCoordinator;
    }

    private function driveDto(ConnectedDrive $drive): array
    {
        return [
            'id' => $drive->id,
            'uuid' => $drive->uuid,
            'name' => $drive->name,
            'provider' => $drive->provider->value,
            'label' => $drive->provider->label(),
            'status' => $drive->status->value,
            'is_default' => (bool) $drive->is_default,
        ];
    }

    private function assertWorkspaceDrive(Model $workspace, ConnectedDrive $drive): void
    {
        if ((int) $drive->workspace_id !== (int) $workspace->getKey()) {
            throw new ResourceNotFoundException;
        }
    }

    private function assertWorkspaceFolder(Model $workspace, Folder $folder): void
    {
        if ((int) ($folder->workspace_id ?? 0) !== (int) $workspace->getKey()) {
            throw new ResourceNotFoundException;
        }
    }

    private function assertWorkspaceMedia(Model $workspace, Media $media): void
    {
        if ((int) ($media->workspace_id ?? 0) !== (int) $workspace->getKey()) {
            throw new ResourceNotFoundException;
        }
    }

    /**
     * Recursively copy all contents of $sourceFolderId into $targetFolderId.
     *
     * @return array{0: int, 1: int, 2: array} [filesCopied, foldersCreated, errors]
     */
    private function recursiveCopy(
        CloudAdapter $from,
        CloudAdapter $to,
        string $sourceFolderId,
        string $targetFolderId,
    ): array {
        $filesCopied = 0;
        $foldersCreated = 0;
        $errors = [];

        $items = $from->listFolder($sourceFolderId);

        foreach ($items as $item) {
            if ($item->isFolder) {
                try {
                    $newFolder = $to->createFolder($targetFolderId, $item->name);
                    $foldersCreated++;

                    [$fc, $dc, $errs] = $this->recursiveCopy($from, $to, $item->id, $newFolder->id);
                    $filesCopied += $fc;
                    $foldersCreated += $dc;
                    $errors = array_merge($errors, $errs);
                } catch (\Throwable $e) {
                    $errors[] = ['path' => $item->name, 'error' => $e->getMessage()];
                }
            } else {
                try {
                    $binary = $from->getFileBinary($item->id);
                    $to->putFile($targetFolderId, $item->name, $binary, $item->mimeType ?? 'application/octet-stream');
                    $filesCopied++;
                } catch (\Throwable $e) {
                    $errors[] = ['path' => $item->name, 'error' => $e->getMessage()];
                }
            }
        }

        return [$filesCopied, $foldersCreated, $errors];
    }
}
