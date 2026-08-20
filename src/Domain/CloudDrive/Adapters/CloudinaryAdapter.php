<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Cloudinary\Cloudinary;
use Cloudinary\Api\Admin\AdminApi;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Configuration\Configuration;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * CloudAdapter for Cloudinary.
 *
 * Cloudinary organises files by public_id with '/' as a folder separator.
 * Our folderId maps to a Cloudinary folder path:
 *   'root'       → '' (account root)
 *   'marketing'  → 'marketing'
 *   'marketing/banners' → 'marketing/banners'
 *
 * Note: Cloudinary is primarily designed for images and videos.
 * Raw files (documents, archives) are uploaded with resource_type='raw'.
 * This adapter auto-detects via MIME type and uses 'auto' to let Cloudinary decide.
 */
class CloudinaryAdapter implements CloudAdapter
{
    private Cloudinary $cloudinary;
    private UploadApi  $uploadApi;
    private AdminApi   $adminApi;

    public function __construct(
        private string $cloudName,
        private string $apiKey,
        private string $apiSecret,
    ) {
        $config = Configuration::instance([
            'cloud' => [
                'cloud_name' => $cloudName,
                'api_key'    => $apiKey,
                'api_secret' => $apiSecret,
            ],
            'url' => ['secure' => true],
        ]);

        $this->cloudinary = new Cloudinary($config);
        $this->uploadApi  = $this->cloudinary->uploadApi();
        $this->adminApi   = $this->cloudinary->adminApi();
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $prefix   = $folderId === 'root' ? '' : rtrim($folderId, '/').'/';
        $results  = [];

        // List sub-folders
        $folderResponse = $prefix === ''
            ? $this->adminApi->rootFolders()
            : $this->adminApi->subFolders($prefix);

        foreach ($folderResponse['folders'] ?? [] as $folder) {
            $results[] = new CloudFile(
                id:           $folder['path'],
                name:         $folder['name'],
                isFolder:     true,
                size:         null,
                mimeType:     null,
                webViewLink:  null,
                thumbnailUrl: null,
                modifiedAt:   null,
                parentId:     $folderId,
            );
        }

        // List assets in the folder
        $nextCursor = null;
        do {
            $options = [
                'type'        => 'upload',
                'max_results' => 500,
                'prefix'      => $prefix,
            ];
            if ($nextCursor) {
                $options['next_cursor'] = $nextCursor;
            }

            $assetResponse = $this->adminApi->assets($options);
            foreach ($assetResponse['resources'] ?? [] as $asset) {
                // Only include direct children (no nested folder assets)
                $relativePath = $prefix !== ''
                    ? ltrim(substr($asset['public_id'], strlen($prefix)), '/')
                    : $asset['public_id'];

                if (str_contains($relativePath, '/')) {
                    continue; // skip assets in sub-folders
                }

                $results[] = $this->toCloudFile($asset, $folderId);
            }

            $nextCursor = $assetResponse['next_cursor'] ?? null;
        } while ($nextCursor !== null);

        return $results;
    }

    public function getFileBinary(string $fileId): string
    {
        $meta = $this->adminApi->asset($fileId);
        $url  = $meta['secure_url'] ?? null;

        if (! $url) {
            throw new RuntimeException("Cannot resolve download URL for Cloudinary asset: {$fileId}");
        }

        $response = Http::get($url);

        if (! $response->successful()) {
            throw new RuntimeException("Failed to download Cloudinary asset {$fileId}: HTTP {$response->status()}");
        }

        return $response->body();
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $folder   = $folderId === 'root' ? '' : rtrim($folderId, '/');
        $basename = pathinfo($name, PATHINFO_FILENAME);
        $publicId = $folder !== '' ? "{$folder}/{$basename}" : $basename;

        $result = $this->uploadApi->upload(
            'data:'.($mimeType ?: 'application/octet-stream').';base64,'.base64_encode($binary),
            [
                'public_id'     => $publicId,
                'resource_type' => 'auto',
                'overwrite'     => true,
                'use_filename'  => true,
            ]
        );

        return $this->toCloudFile($result, $folderId);
    }

    public function deleteFile(string $fileId): void
    {
        // Try as a folder first
        try {
            $this->adminApi->deleteFolder($fileId);
            return;
        } catch (\Throwable) {
            // Not a folder — delete as asset
        }

        $this->uploadApi->destroy($fileId, ['resource_type' => 'raw']);
        $this->uploadApi->destroy($fileId, ['resource_type' => 'image']);
        $this->uploadApi->destroy($fileId, ['resource_type' => 'video']);
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $parentId === 'root' ? $name : rtrim($parentId, '/').'/'.$name;

        $this->adminApi->createFolder($path);

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
        try {
            $meta = $this->adminApi->asset($fileId);
            return $this->toCloudFile($meta, null);
        } catch (\Throwable $e) {
            throw new RuntimeException("Cloudinary asset not found: {$fileId}", 0, $e);
        }
    }

    public function refreshToken(): array
    {
        return [];
    }

    /** @internal for test injection */
    public function setUploadApi(UploadApi $api): void
    {
        $this->uploadApi = $api;
    }

    /** @internal for test injection */
    public function setAdminApi(AdminApi $api): void
    {
        $this->adminApi = $api;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function toCloudFile(array $asset, ?string $parentId): CloudFile
    {
        $publicId = $asset['public_id'] ?? '';
        $name     = basename($publicId);

        $parent = $parentId ?? (
            str_contains($publicId, '/')
                ? ltrim(dirname($publicId), '/')
                : 'root'
        );

        $modifiedAt = null;
        if (! empty($asset['created_at'])) {
            $modifiedAt = Carbon::parse($asset['created_at']);
        }

        return new CloudFile(
            id:           $publicId,
            name:         $name,
            isFolder:     false,
            size:         isset($asset['bytes']) ? (int) $asset['bytes'] : null,
            mimeType:     $asset['format'] ? $this->guessMediaType($asset) : null,
            webViewLink:  $asset['secure_url'] ?? null,
            thumbnailUrl: $asset['secure_url'] ?? null,
            modifiedAt:   $modifiedAt,
            parentId:     $parent,
        );
    }

    private function guessMediaType(array $asset): ?string
    {
        $resourceType = $asset['resource_type'] ?? 'image';
        $format       = $asset['format'] ?? null;

        if (! $format) {
            return null;
        }

        return match($resourceType) {
            'image' => 'image/'.$format,
            'video' => 'video/'.$format,
            'raw'   => 'application/octet-stream',
            default => null,
        };
    }
}
