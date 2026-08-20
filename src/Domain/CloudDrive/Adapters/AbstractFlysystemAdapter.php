<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemOperator;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use RuntimeException;

/**
 * Shared CloudAdapter implementation backed by a League\Flysystem\FilesystemOperator.
 * Concrete subclasses only need to wire up $this->disk in their constructor.
 */
abstract class AbstractFlysystemAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    protected FilesystemOperator $disk;

    public function listFolder(string $folderId = 'root'): array
    {
        $prefix  = $folderId === 'root' ? '' : rtrim($folderId, '/').'/';
        $listing = $this->disk->listContents($prefix, false);
        $results = [];

        foreach ($listing as $item) {
            $path   = $item->path();
            $name   = basename($path);
            $parent = $prefix === '' ? 'root' : rtrim($prefix, '/');

            if ($item instanceof DirectoryAttributes) {
                $results[] = new CloudFile(
                    id:           $path,
                    name:         $name,
                    isFolder:     true,
                    size:         null,
                    mimeType:     null,
                    webViewLink:  null,
                    thumbnailUrl: null,
                    modifiedAt:   null,
                    parentId:     $parent,
                );
            } elseif ($item instanceof FileAttributes) {
                $lastMod = $item->lastModified();

                $results[] = new CloudFile(
                    id:           $path,
                    name:         $name,
                    isFolder:     false,
                    size:         $item->fileSize(),
                    mimeType:     $item->mimeType(),
                    webViewLink:  null,
                    thumbnailUrl: null,
                    modifiedAt:   $lastMod ? Carbon::createFromTimestamp($lastMod) : null,
                    parentId:     $parent,
                );
            }
        }

        return $results;
    }

    public function getFileBinary(string $fileId): string
    {
        try {
            return $this->disk->read($fileId);
        } catch (\Throwable $e) {
            throw new RuntimeException("File not found: {$fileId}", 0, $e);
        }
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $path = $this->resolvePath($folderId, $name);

        $this->disk->write($path, $binary, ['mimetype' => $mimeType]);

        return new CloudFile(
            id:           $path,
            name:         $name,
            isFolder:     false,
            size:         strlen($binary),
            mimeType:     $mimeType,
            webViewLink:  null,
            thumbnailUrl: null,
            modifiedAt:   Carbon::now(),
            parentId:     $folderId,
        );
    }

    public function deleteFile(string $fileId): void
    {
        try {
            if ($this->disk->directoryExists($fileId)) {
                $this->disk->deleteDirectory($fileId);
            } else {
                $this->disk->delete($fileId);
            }
        } catch (\Throwable) {
            // file already gone
        }
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $this->resolvePath($parentId, $name);

        $this->disk->createDirectory($path);

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
        $name = basename($fileId);

        if ($this->disk->directoryExists($fileId)) {
            return new CloudFile(
                id: $fileId, name: $name, isFolder: true,
                size: null, mimeType: null, webViewLink: null, thumbnailUrl: null, modifiedAt: null,
            );
        }

        if (! $this->disk->fileExists($fileId)) {
            throw new RuntimeException("File not found: {$fileId}");
        }

        $lastMod = $this->tryInt(fn () => $this->disk->lastModified($fileId));

        return new CloudFile(
            id:           $fileId,
            name:         $name,
            isFolder:     false,
            size:         $this->tryInt(fn () => $this->disk->fileSize($fileId)),
            mimeType:     $this->tryString(fn () => $this->disk->mimeType($fileId)),
            webViewLink:  null,
            thumbnailUrl: null,
            modifiedAt:   $lastMod ? Carbon::createFromTimestamp($lastMod) : null,
        );
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $targetPath = $this->resolvePath($targetFolderId, $name);

        $this->disk->copy($fileId, $targetPath);

        return $this->getMetadata($targetPath);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $targetPath = $this->resolvePath($targetFolderId, $name);

        $this->disk->move($fileId, $targetPath);

        return $this->getMetadata($targetPath);
    }

    public function refreshToken(): array
    {
        return [];
    }

    /** @internal for test injection */
    public function setFilesystem(FilesystemOperator $filesystem): void
    {
        $this->disk = $filesystem;
    }

    protected function resolvePath(string $folderId, string $name): string
    {
        $prefix = $folderId === 'root' ? '' : rtrim($folderId, '/').'/';

        return $prefix.$name;
    }

    private function tryInt(\Closure $fn): ?int
    {
        try { return $fn(); } catch (\Throwable) { return null; }
    }

    private function tryString(\Closure $fn): ?string
    {
        try { return $fn(); } catch (\Throwable) { return null; }
    }
}
