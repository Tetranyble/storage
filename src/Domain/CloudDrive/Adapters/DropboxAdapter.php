<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Spatie\Dropbox\Client;
use RuntimeException;

class DropboxAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private Client $client;

    public function __construct(private string $accessToken)
    {
        $this->client = new Client($this->accessToken);
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $path     = $this->dbxPath($folderId);
        $response = $this->client->listFolder($path);
        $entries  = $response['entries'] ?? [];

        while (($response['has_more'] ?? false) && isset($response['cursor'])) {
            $response = $this->client->listFolderContinue($response['cursor']);
            $entries  = array_merge($entries, $response['entries'] ?? []);
        }

        return array_map(fn (array $entry) => $this->toCloudFile($entry, $folderId), $entries);
    }

    public function getFileBinary(string $fileId): string
    {
        [$metadata, $body] = $this->client->download($this->dbxPath($fileId));

        return (string) $body;
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $path     = $this->joinPath($folderId, $name);
        $metadata = $this->client->upload($this->dbxPath($path), $binary, 'overwrite');

        return $this->toCloudFile($metadata, $folderId);
    }

    public function deleteFile(string $fileId): void
    {
        $this->client->delete($this->dbxPath($fileId));
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path   = $this->joinPath($parentId, $name);
        $result = $this->client->createFolder($this->dbxPath($path));
        // spatie v1 returns the folder metadata directly
        $meta   = $result['metadata'] ?? $result;

        return $this->toCloudFile($meta, $parentId);
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $meta = $this->client->getMetadata($this->dbxPath($fileId));

        return $this->toCloudFile($meta, null);
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $toPath = $this->dbxPath($this->joinPath($targetFolderId, $name));
        $result = $this->client->copy($this->dbxPath($fileId), $toPath);
        $meta   = $result['metadata'] ?? $result;

        return $this->toCloudFile($meta, $targetFolderId);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $toPath = $this->dbxPath($this->joinPath($targetFolderId, $name));
        $result = $this->client->move($this->dbxPath($fileId), $toPath);
        $meta   = $result['metadata'] ?? $result;

        return $this->toCloudFile($meta, $targetFolderId);
    }

    public function refreshToken(): array
    {
        // Dropbox short-lived token refresh is managed externally via OAuthService
        return [];
    }

    /** @internal for test injection */
    public function setClient(Client $client): void
    {
        $this->client = $client;
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * Convert our internal folderId to a Dropbox API path.
     * 'root' → '' (Dropbox root), otherwise prepend '/'.
     */
    private function dbxPath(string $id): string
    {
        if ($id === 'root' || $id === '') {
            return '';
        }

        return '/'.ltrim($id, '/');
    }

    private function joinPath(string $folderId, string $name): string
    {
        if ($folderId === 'root') {
            return $name;
        }

        return rtrim($folderId, '/').'/'.$name;
    }

    private function toCloudFile(array $entry, ?string $parentId): CloudFile
    {
        $isFolder    = ($entry['.tag'] ?? '') === 'folder';
        $pathDisplay = $entry['path_display'] ?? $entry['name'] ?? '';

        // Strip leading slash to produce our internal ID
        $id = ltrim($pathDisplay, '/') ?: ($entry['name'] ?? '');

        $parent = $parentId ?? (
            $pathDisplay !== '' && dirname($pathDisplay) !== '/'
                ? ltrim(dirname($pathDisplay), '/')
                : 'root'
        );

        return new CloudFile(
            id:           $id,
            name:         $entry['name'] ?? basename($pathDisplay),
            isFolder:     $isFolder,
            size:         isset($entry['size']) ? (int) $entry['size'] : null,
            mimeType:     null,
            webViewLink:  null,
            thumbnailUrl: null,
            modifiedAt:   isset($entry['server_modified'])
                ? Carbon::parse($entry['server_modified'])
                : null,
            parentId:     $parent,
        );
    }
}
