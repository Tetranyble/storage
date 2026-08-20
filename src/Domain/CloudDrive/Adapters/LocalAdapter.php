<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * CloudAdapter backed by a configured Laravel filesystem disk.
 *
 * Pass any disk name that is defined in your filesystems.php config — typically
 * 'local' (private) or 'public' (web-accessible). Both are supported through
 * the same adapter; the disk name is stored in ConnectedDrive.credentials['disk'].
 */
class LocalAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private Filesystem $disk;

    public function __construct(private string $diskName = 'local')
    {
        $this->disk = Storage::disk($this->diskName);
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $prefix = ($folderId === 'root') ? '' : rtrim($folderId, '/').'/';

        $directories = $this->disk->directories($prefix === '' ? null : $prefix);
        $files       = $this->disk->files($prefix === '' ? null : $prefix);

        $results = [];

        foreach ($directories as $dir) {
            $results[] = new CloudFile(
                id:           $dir,
                name:         basename($dir),
                isFolder:     true,
                size:         null,
                mimeType:     null,
                webViewLink:  null,
                thumbnailUrl: null,
                modifiedAt:   null,
                parentId:     $prefix === '' ? 'root' : $prefix,
            );
        }

        foreach ($files as $file) {
            $lastMod = $this->disk->lastModified($file);

            $results[] = new CloudFile(
                id:           $file,
                name:         basename($file),
                isFolder:     false,
                size:         $this->disk->size($file) ?: null,
                mimeType:     $this->disk->mimeType($file) ?: null,
                webViewLink:  $this->publicUrl($file),
                thumbnailUrl: null,
                modifiedAt:   $lastMod ? Carbon::createFromTimestamp($lastMod) : null,
                parentId:     $prefix === '' ? 'root' : $prefix,
            );
        }

        return $results;
    }

    public function getFileBinary(string $fileId): string
    {
        $content = $this->disk->get($fileId);

        if ($content === null) {
            throw new RuntimeException("Local file not found: {$fileId}");
        }

        return $content;
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $path = $this->resolvePath($folderId, $name);

        $this->disk->put($path, $binary);

        return new CloudFile(
            id:           $path,
            name:         $name,
            isFolder:     false,
            size:         strlen($binary),
            mimeType:     $mimeType,
            webViewLink:  $this->publicUrl($path),
            thumbnailUrl: null,
            modifiedAt:   Carbon::now(),
            parentId:     $folderId,
        );
    }

    public function deleteFile(string $fileId): void
    {
        if ($this->disk->directoryExists($fileId)) {
            $this->disk->deleteDirectory($fileId);
        } else {
            $this->disk->delete($fileId);
        }
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $this->resolvePath($parentId, $name);

        $this->disk->makeDirectory($path);

        return new CloudFile(
            id:           $path,
            name:         $name,
            isFolder:     true,
            size:         null,
            mimeType:     null,
            webViewLink:  null,
            thumbnailUrl: null,
            modifiedAt:   Carbon::now(),
            parentId:     $parentId,
        );
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $name     = basename($fileId);
        $isFolder = $this->disk->directoryExists($fileId);

        if ($isFolder) {
            return new CloudFile(
                id:       $fileId,
                name:     $name,
                isFolder: true,
                size:     null, mimeType: null, webViewLink: null, thumbnailUrl: null, modifiedAt: null,
            );
        }

        if (! $this->disk->fileExists($fileId)) {
            throw new RuntimeException("Local file not found: {$fileId}");
        }

        $lastMod = $this->disk->lastModified($fileId);

        return new CloudFile(
            id:           $fileId,
            name:         $name,
            isFolder:     false,
            size:         $this->disk->size($fileId) ?: null,
            mimeType:     $this->disk->mimeType($fileId) ?: null,
            webViewLink:  $this->publicUrl($fileId),
            thumbnailUrl: null,
            modifiedAt:   $lastMod ? Carbon::createFromTimestamp($lastMod) : null,
        );
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $targetPath = $this->resolvePath($targetFolderId, $name);

        if (! $this->disk->copy($fileId, $targetPath)) {
            throw new RuntimeException("Local copy failed: {$fileId} → {$targetPath}");
        }

        return $this->getMetadata($targetPath);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $targetPath = $this->resolvePath($targetFolderId, $name);

        if (! $this->disk->move($fileId, $targetPath)) {
            throw new RuntimeException("Local move failed: {$fileId} → {$targetPath}");
        }

        return $this->getMetadata($targetPath);
    }

    public function refreshToken(): array
    {
        return [];
    }

    public function diskName(): string
    {
        return $this->diskName;
    }

    /** @internal for test injection */
    public function setDisk(Filesystem $disk): void
    {
        $this->disk = $disk;
    }

    private function resolvePath(string $folderId, string $name): string
    {
        $prefix = ($folderId === 'root') ? '' : rtrim($folderId, '/').'/';

        return $prefix.$name;
    }

    private function publicUrl(string $path): ?string
    {
        try {
            return $this->disk->url($path);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
