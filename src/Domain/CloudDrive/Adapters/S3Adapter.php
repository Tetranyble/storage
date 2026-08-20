<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class S3Adapter implements CloudAdapter, SupportsSameDriveOperations
{
    private \Illuminate\Contracts\Filesystem\Filesystem $disk;

    public function __construct(
        private string $bucket,
        private string $key,
        private string $secret,
        private string $region,
        private string $url = '',
        private string $endpoint = '',
    ) {
        $this->disk = Storage::build($this->diskConfig());
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $prefix = ($folderId === 'root') ? '' : rtrim($folderId, '/').'/';

        $directories = $this->disk->directories($prefix === '' ? null : $prefix);
        $files       = $this->disk->files($prefix === '' ? null : $prefix);

        $results = [];

        foreach ($directories as $dir) {
            $name = basename($dir);
            $results[] = new CloudFile(
                id:           $dir,
                name:         $name,
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
            $name       = basename($file);
            $size       = $this->disk->size($file);
            $lastMod    = $this->disk->lastModified($file);
            $mimeType   = $this->disk->mimeType($file) ?: null;

            $results[] = new CloudFile(
                id:           $file,
                name:         $name,
                isFolder:     false,
                size:         $size ?: null,
                mimeType:     $mimeType,
                webViewLink:  $this->disk->url($file),
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
            throw new RuntimeException("S3 file not found: {$fileId}");
        }

        return $content;
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $prefix = ($folderId === 'root') ? '' : rtrim($folderId, '/').'/';
        $path   = $prefix.$name;

        $this->disk->put($path, $binary, ['ContentType' => $mimeType]);

        return new CloudFile(
            id:           $path,
            name:         $name,
            isFolder:     false,
            size:         strlen($binary),
            mimeType:     $mimeType,
            webViewLink:  $this->disk->url($path),
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
        $prefix = ($parentId === 'root') ? '' : rtrim($parentId, '/').'/';
        $path   = $prefix.$name;

        // S3 folders are virtual — put a .keep object
        $this->disk->put($path.'/.keep', '');

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

        $size    = $this->disk->size($fileId);
        $lastMod = $this->disk->lastModified($fileId);
        $mime    = $this->disk->mimeType($fileId) ?: null;

        return new CloudFile(
            id:           $fileId,
            name:         $name,
            isFolder:     false,
            size:         $size ?: null,
            mimeType:     $mime,
            webViewLink:  $this->disk->url($fileId),
            thumbnailUrl: null,
            modifiedAt:   $lastMod ? Carbon::createFromTimestamp($lastMod) : null,
        );
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $targetPath = $this->resolvePath($targetFolderId, $name);

        if (! $this->disk->copy($fileId, $targetPath)) {
            throw new RuntimeException("S3 copy failed: {$fileId} → {$targetPath}");
        }

        return $this->getMetadata($targetPath);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $result = $this->copyFileSameDrive($fileId, $targetFolderId, $name);
        $this->disk->delete($fileId);

        return $result;
    }

    public function refreshToken(): array
    {
        // S3 uses static credentials — no OAuth token to refresh
        return [];
    }

    private function resolvePath(string $folderId, string $name): string
    {
        $prefix = ($folderId === 'root') ? '' : rtrim($folderId, '/').'/';

        return $prefix.$name;
    }

    private function diskConfig(): array
    {
        $config = [
            'driver' => 's3',
            'key'    => $this->key,
            'secret' => $this->secret,
            'region' => $this->region,
            'bucket' => $this->bucket,
        ];

        if ($this->url !== '') {
            $config['url'] = $this->url;
        }

        if ($this->endpoint !== '') {
            $config['endpoint']             = $this->endpoint;
            $config['use_path_style_endpoint'] = true;
        }

        return $config;
    }
}
