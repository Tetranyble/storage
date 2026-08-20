<?php

namespace Tetranyble\Storage\Domain\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\Adapters\AzureBlobAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\CloudinaryAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\DropboxAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\GcsAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\GoogleDriveAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\LocalAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\OneDriveAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Adapters\S3Adapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Tetranyble\Storage\Domain\CloudDrive\DTO\TransferResult;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Events\DriveConnected;
use Tetranyble\Storage\Events\DriveDisconnected;
use Tetranyble\Storage\Models\ConnectedDrive;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Contracts\StorageTransferAuthorizer;

class ConnectedDriveService
{
    public function __construct(
        private readonly OAuthService       $oauth,
        private readonly FileSystemContract $files,
        private readonly StorageService     $storage,
        private readonly ?StorageTransferAuthorizer $transferAuthorization = null,
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
        array         $tokenData,
        string        $name,
    ): ConnectedDrive {
        if (! $provider->supportsOAuth()) {
            throw new RuntimeException("{$provider->label()} does not use OAuth.");
        }

        $drive = DB::transaction(function () use ($workspace, $provider, $tokenData, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'             => (string) Str::uuid(),
                'workspace_id'        => $workspace->id,
                'provider'         => $provider,
                'name'             => $name,
                'access_token'     => $tokenData['access_token'],
                'refresh_token'    => $tokenData['refresh_token'] ?? null,
                'token_expires_at' => $tokenData['expires_at'] ?? null,
                'credentials'      => [],
                'status'           => ConnectedDriveStatus::CONNECTED,
                'is_default'       => $isFirst,
                'connected_at'     => now(),
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
        $drive = DB::transaction(function () use ($workspace, $diskName, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'         => (string) Str::uuid(),
                'workspace_id'    => $workspace->id,
                'provider'     => CloudProvider::LOCAL,
                'name'         => $name,
                'credentials'  => ['disk' => $diskName],
                'status'       => ConnectedDriveStatus::CONNECTED,
                'is_default'   => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Connect an Azure Blob Storage container.
     * Supply either a full $connectionString, or account_name + account_key in $credentials.
     *
     * Required keys: one of:
     *   - 'connection_string' + 'container'
     *   - 'account_name' + 'account_key' + 'container'
     */
    public function connectAzureBlob(
        Model $workspace,
        array  $credentials,
        string $name,
    ): ConnectedDrive {
        if (empty($credentials['container'])) {
            throw new RuntimeException("Azure Blob credentials must include 'container'.");
        }
        if (empty($credentials['connection_string']) && (empty($credentials['account_name']) || empty($credentials['account_key']))) {
            throw new RuntimeException("Azure Blob credentials must include 'connection_string' or 'account_name'+'account_key'.");
        }

        $drive = DB::transaction(function () use ($workspace, $credentials, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'         => (string) Str::uuid(),
                'workspace_id'    => $workspace->id,
                'provider'     => CloudProvider::AZURE_BLOB,
                'name'         => $name,
                'credentials'  => $credentials,
                'status'       => ConnectedDriveStatus::CONNECTED,
                'is_default'   => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Connect a Google Cloud Storage bucket using a service account key file.
     *
     * Required keys: 'key_file' (array decoded from the JSON key file), 'bucket'.
     * Optional: 'path_prefix' to scope this drive to a sub-path within the bucket.
     */
    public function connectGcs(
        Model $workspace,
        array  $credentials,
        string $name,
    ): ConnectedDrive {
        if (empty($credentials['key_file']) || ! is_array($credentials['key_file'])) {
            throw new RuntimeException("GCS credentials must include 'key_file' (decoded JSON key array).");
        }
        if (empty($credentials['bucket'])) {
            throw new RuntimeException("GCS credentials must include 'bucket'.");
        }

        $drive = DB::transaction(function () use ($workspace, $credentials, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'         => (string) Str::uuid(),
                'workspace_id'    => $workspace->id,
                'provider'     => CloudProvider::GCS,
                'name'         => $name,
                'credentials'  => $credentials,
                'status'       => ConnectedDriveStatus::CONNECTED,
                'is_default'   => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Connect a Cloudinary account using API credentials.
     *
     * Required keys: 'cloud_name', 'api_key', 'api_secret'.
     */
    public function connectCloudinary(
        Model $workspace,
        array  $credentials,
        string $name,
    ): ConnectedDrive {
        foreach (['cloud_name', 'api_key', 'api_secret'] as $field) {
            if (empty($credentials[$field])) {
                throw new RuntimeException("Cloudinary credentials must include '{$field}'.");
            }
        }

        $drive = DB::transaction(function () use ($workspace, $credentials, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'         => (string) Str::uuid(),
                'workspace_id'    => $workspace->id,
                'provider'     => CloudProvider::CLOUDINARY,
                'name'         => $name,
                'credentials'  => $credentials,
                'status'       => ConnectedDriveStatus::CONNECTED,
                'is_default'   => $isFirst,
                'connected_at' => now(),
            ]);
        });

        Event::dispatch(new DriveConnected($drive, null));

        return $drive;
    }

    /**
     * Connect an Amazon S3 bucket using static credentials.
     * The drive is automatically set as default if it is the first one for the workspace.
     */
    public function connectS3(
        Model $workspace,
        array   $credentials,
        string  $name,
    ): ConnectedDrive {
        $required = ['bucket', 'key', 'secret', 'region'];
        foreach ($required as $field) {
            if (empty($credentials[$field])) {
                throw new RuntimeException("S3 credentials must include '{$field}'.");
            }
        }

        // Smoke-test the credentials before persisting
        $this->buildS3Adapter($credentials)->listFolder('root');

        $drive = DB::transaction(function () use ($workspace, $credentials, $name): ConnectedDrive {
            $isFirst = ! $this->hasAnyConnected($workspace);

            return ConnectedDrive::create([
                'uuid'         => (string) Str::uuid(),
                'workspace_id'    => $workspace->id,
                'provider'     => CloudProvider::S3,
                'name'         => $name,
                'credentials'  => $credentials,
                'status'       => ConnectedDriveStatus::CONNECTED,
                'is_default'   => $isFirst,
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
                'status'     => ConnectedDriveStatus::DISCONNECTED,
                'is_default' => false,
                'default_slot' => null,
            ])->save();
            $drive->delete();
        });

        if ($wasDefault) {
            $this->promoteOldestAsDefault($workspace);
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

        DB::transaction(function () use ($workspace, $drive): void {
            ConnectedDrive::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->get();
            ConnectedDrive::query()
                ->where('workspace_id', $workspace->id)
                ->update(['is_default' => false, 'default_slot' => null]);

            $drive->forceFill(['is_default' => true, 'default_slot' => 'default'])->save();
        });
    }

    /**
     * Get the workspace's current default drive, or null if none is connected.
     */
    public function getDefault(Model $workspace): ?ConnectedDrive
    {
        return ConnectedDrive::query()
            ->where('workspace_id', $workspace->id)
            ->where('is_default', true)
            ->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->first();
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
            ->where('workspace_id', $workspace->id)
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
        ?ConnectedDrive $drive    = null,
        string          $folderId = 'root',
    ): array {
        $drive   = $this->resolveDrive($workspace, $drive);
        $adapter = $this->adapterFor($drive);
        $items   = $adapter->listFolder($folderId);

        return [
            'drive'  => $this->driveDto($drive),
            'folder' => $folderId,
            'items'  => array_map(fn (CloudFile $f) => $f->toArray(), $items),
            'count'  => count($items),
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
        string         $fileId,
        ConnectedDrive $to,
        string         $targetFolderId = 'root',
        ?string        $newName        = null,
        ?Model         $actor          = null,
    ): CloudFile {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeCopy($workspace, $from, $to, $actor);

        $fromAdapter = $this->adapterFor($from);

        if ($from->id === $to->id && $fromAdapter instanceof SupportsSameDriveOperations) {
            $name = $newName ?? $fromAdapter->getMetadata($fileId)->name;

            return $fromAdapter->copyFileSameDrive($fileId, $targetFolderId, $name);
        }

        $meta   = $fromAdapter->getMetadata($fileId);
        $name   = $newName ?? $meta->name;
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
        string         $fileId,
        ConnectedDrive $to,
        string         $targetFolderId = 'root',
        ?string        $newName        = null,
        ?Model         $actor          = null,
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
        string         $folderId,
        ConnectedDrive $to,
        string         $targetParentId = 'root',
        ?string        $newName        = null,
        ?Model         $actor          = null,
    ): TransferResult {
        $this->assertWorkspaceDrive($workspace, $from);
        $this->assertWorkspaceDrive($workspace, $to);
        $this->transferAuthorization?->authorizeCopy($workspace, $from, $to, $actor);

        $fromAdapter = $this->adapterFor($from);
        $toAdapter   = $this->adapterFor($to);

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
        string         $folderId,
        ConnectedDrive $to,
        string         $targetParentId = 'root',
        ?string        $newName        = null,
        ?Model         $actor          = null,
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
        ConnectedDrive  $drive,
        string          $remoteFileId,
        Folder          $targetFolder,
        Model $actor,
    ): Media {
        $this->assertWorkspaceDrive($workspace, $drive);
        $this->assertWorkspaceFolder($workspace, $targetFolder);

        $adapter  = $this->adapterFor($drive);
        $metadata = $adapter->getMetadata($remoteFileId);

        if ($metadata->isFolder) {
            throw new RuntimeException('Cannot import a folder as a Media record. Import individual files.');
        }

        $binary = $adapter->getFileBinary($remoteFileId);
        $size   = strlen($binary);

        $this->storage->ensureQuota($workspace, $size);

        $disk      = $this->files->getDefaultDisk();
        $extension = pathinfo($metadata->name, PATHINFO_EXTENSION);
        $path      = $targetFolder->path.'/'.Str::uuid().($extension ? ".{$extension}" : '');

        $this->files->put($path, $binary, $disk);

        return DB::transaction(function () use ($workspace, $actor, $targetFolder, $metadata, $disk, $path, $size): Media {
            $media = Media::create([
                'workspace_id'     => $workspace->id,
                'folder_id'     => $targetFolder->id,
                'uploaded_by'   => $actor->id,
                'original_name' => $metadata->name,
                'mime_type'     => $metadata->mimeType ?? 'application/octet-stream',
                'size'          => $size,
                'path'          => $path,
                'disk'          => $disk,
                'status'        => MediaStatus::COMPLETE,
                'uploaded_at'   => now(),
                'current'       => true,
            ]);

            $this->storage->increaseUsage($workspace, $size);

            return $media;
        });
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
        Media           $media,
        ?ConnectedDrive $drive          = null,
        string          $remoteFolderId = 'root',
    ): CloudFile {
        $drive = $this->resolveDrive($workspace, $drive);
        $this->assertWorkspaceMedia($workspace, $media);

        $adapter  = $this->adapterFor($drive);
        $binary   = $this->files->get($media->path, $media->disk);
        $mimeType = $media->mime_type ?? 'application/octet-stream';

        return $adapter->putFile($remoteFolderId, $media->original_name ?? basename($media->path), $binary, $mimeType);
    }

    // ---------------------------------------------------------------
    // Adapter factory
    // ---------------------------------------------------------------

    public function adapterFor(ConnectedDrive $drive): CloudAdapter
    {
        $noOAuth = [
            CloudProvider::S3, CloudProvider::AZURE_BLOB, CloudProvider::GCS,
            CloudProvider::CLOUDINARY, CloudProvider::LOCAL, CloudProvider::DROPBOX,
        ];
        if (! in_array($drive->provider, $noOAuth, true) && $drive->isTokenExpiringSoon()) {
            $drive = $this->oauth->refreshAccessToken($drive);
        }

        return match($drive->provider) {
            CloudProvider::GOOGLE_DRIVE => $this->buildGoogleAdapter($drive),
            CloudProvider::ONEDRIVE     => $this->buildOneDriveAdapter($drive),
            CloudProvider::DROPBOX      => $this->buildDropboxAdapter($drive),
            CloudProvider::S3           => $this->buildS3Adapter($drive->credentials ?? []),
            CloudProvider::AZURE_BLOB   => $this->buildAzureBlobAdapter($drive->credentials ?? []),
            CloudProvider::GCS          => $this->buildGcsAdapter($drive->credentials ?? []),
            CloudProvider::CLOUDINARY   => $this->buildCloudinaryAdapter($drive->credentials ?? []),
            CloudProvider::LOCAL        => $this->buildLocalAdapter($drive),
        };
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function hasAnyConnected(Model $workspace): bool
    {
        return ConnectedDrive::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->exists();
    }

    private function promoteOldestAsDefault(Model $workspace): void
    {
        $next = ConnectedDrive::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->oldest('connected_at')
            ->first();

        if ($next) {
            $next->forceFill(['is_default' => true, 'default_slot' => 'default'])->save();
        }
    }

    private function buildGoogleAdapter(ConnectedDrive $drive): GoogleDriveAdapter
    {
        return new GoogleDriveAdapter(
            accessToken:  $drive->access_token,
            refreshToken: $drive->refresh_token,
            clientId:     config('tetranyble-storage.cloud_drives.google_drive.client_id'),
            clientSecret: config('tetranyble-storage.cloud_drives.google_drive.client_secret'),
        );
    }

    private function buildOneDriveAdapter(ConnectedDrive $drive): OneDriveAdapter
    {
        $creds = $drive->credentials ?? [];

        return new OneDriveAdapter(
            accessToken:  $drive->access_token,
            refreshToken: $drive->refresh_token,
            clientId:     config('tetranyble-storage.cloud_drives.onedrive.client_id'),
            clientSecret: config('tetranyble-storage.cloud_drives.onedrive.client_secret'),
            tenantId:    config('tetranyble-storage.cloud_drives.onedrive.tenant_id', 'common'),
            drivePath:    $creds['drive_path'] ?? '/me/drive',
        );
    }

    private function buildDropboxAdapter(ConnectedDrive $drive): DropboxAdapter
    {
        return new DropboxAdapter($drive->access_token ?? '');
    }

    private function buildAzureBlobAdapter(array $creds): AzureBlobAdapter
    {
        $container = $creds['container'] ?? '';

        if (! empty($creds['connection_string'])) {
            return new AzureBlobAdapter($creds['connection_string'], $container);
        }

        return AzureBlobAdapter::fromCredentials(
            accountName:   $creds['account_name'] ?? '',
            accountKey:    $creds['account_key'] ?? '',
            container:     $container,
        );
    }

    private function buildGcsAdapter(array $creds): GcsAdapter
    {
        return new GcsAdapter(
            keyFile:     $creds['key_file'] ?? [],
            bucket:      $creds['bucket'] ?? '',
            pathPrefix:  $creds['path_prefix'] ?? '',
        );
    }

    private function buildCloudinaryAdapter(array $creds): CloudinaryAdapter
    {
        return new CloudinaryAdapter(
            cloudName:  $creds['cloud_name'] ?? '',
            apiKey:     $creds['api_key'] ?? '',
            apiSecret:  $creds['api_secret'] ?? '',
        );
    }

    private function buildLocalAdapter(ConnectedDrive $drive): LocalAdapter
    {
        $diskName = $drive->credentials['disk'] ?? 'local';

        return new LocalAdapter($diskName);
    }

    private function buildS3Adapter(array $creds): S3Adapter
    {
        return new S3Adapter(
            bucket:   $creds['bucket'] ?? '',
            key:      $creds['key'] ?? '',
            secret:   $creds['secret'] ?? '',
            region:   $creds['region'] ?? 'us-east-1',
            url:      $creds['url'] ?? '',
            endpoint: $creds['endpoint'] ?? '',
        );
    }

    private function driveDto(ConnectedDrive $drive): array
    {
        return [
            'id'         => $drive->id,
            'uuid'       => $drive->uuid,
            'name'       => $drive->name,
            'provider'   => $drive->provider->value,
            'label'      => $drive->provider->label(),
            'status'     => $drive->status->value,
            'is_default' => (bool) $drive->is_default,
        ];
    }

    private function assertWorkspaceDrive(Model $workspace, ConnectedDrive $drive): void
    {
        if ((int) $drive->workspace_id !== (int) $workspace->id) {
            abort(404);
        }
    }

    private function assertWorkspaceFolder(Model $workspace, Folder $folder): void
    {
        if ((int) ($folder->workspace_id ?? 0) !== (int) $workspace->id) {
            abort(404);
        }
    }

    private function assertWorkspaceMedia(Model $workspace, Media $media): void
    {
        if ((int) ($media->workspace_id ?? 0) !== (int) $workspace->id) {
            abort(404);
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
        string       $sourceFolderId,
        string       $targetFolderId,
    ): array {
        $filesCopied    = 0;
        $foldersCreated = 0;
        $errors         = [];

        $items = $from->listFolder($sourceFolderId);

        foreach ($items as $item) {
            if ($item->isFolder) {
                try {
                    $newFolder       = $to->createFolder($targetFolderId, $item->name);
                    $foldersCreated++;

                    [$fc, $dc, $errs] = $this->recursiveCopy($from, $to, $item->id, $newFolder->id);
                    $filesCopied    += $fc;
                    $foldersCreated += $dc;
                    $errors          = array_merge($errors, $errs);
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
