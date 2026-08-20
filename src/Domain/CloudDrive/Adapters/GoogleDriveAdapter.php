<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Google\Client;
use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use RuntimeException;

/**
 * Wraps google/apiclient (Drive v3) behind the CloudAdapter contract.
 */
class GoogleDriveAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private const FOLDER_MIME  = 'application/vnd.google-apps.folder';
    private const FILE_FIELDS  = 'id,name,mimeType,size,webViewLink,thumbnailLink,modifiedTime,parents';

    private Drive  $service;
    private Client $client;

    public function __construct(
        private string  $accessToken,
        private ?string $refreshToken,
        private ?string $clientId,
        private ?string $clientSecret,
    ) {
        $this->client = $this->buildClient();
        $this->service = new Drive($this->client);
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $results = $this->service->files->listFiles([
            'q'        => "'{$folderId}' in parents and trashed = false",
            'fields'   => 'files('.self::FILE_FIELDS.')',
            'pageSize' => 1000,
        ]);

        return array_map(
            fn (DriveFile $file) => $this->toCloudFile($file),
            $results->getFiles() ?? []
        );
    }

    public function getFileBinary(string $fileId): string
    {
        // getFileBinary downloads via the Drive service's HTTP client which follows redirects
        $response = $this->service->files->get($fileId, ['alt' => 'media']);

        return $response->getBody()->getContents();
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $metadata = new DriveFile([
            'name'    => $name,
            'parents' => [$folderId],
        ]);

        $file = $this->service->files->create($metadata, [
            'data'        => $binary,
            'mimeType'    => $mimeType,
            'uploadType'  => 'multipart',
            'fields'      => self::FILE_FIELDS,
        ]);

        return $this->toCloudFile($file);
    }

    public function deleteFile(string $fileId): void
    {
        try {
            $this->service->files->delete($fileId);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() !== 404) {
                throw new RuntimeException("Google Drive delete failed: {$e->getMessage()}", 0, $e);
            }
        }
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $metadata = new DriveFile([
            'name'     => $name,
            'mimeType' => self::FOLDER_MIME,
            'parents'  => [$parentId],
        ]);

        $folder = $this->service->files->create($metadata, ['fields' => self::FILE_FIELDS]);

        return $this->toCloudFile($folder);
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $file = $this->service->files->get($fileId, ['fields' => self::FILE_FIELDS]);

        return $this->toCloudFile($file);
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $metadata = new DriveFile(['name' => $name, 'parents' => [$targetFolderId]]);

        $copied = $this->service->files->copy($fileId, $metadata, ['fields' => self::FILE_FIELDS]);

        return $this->toCloudFile($copied);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        // Fetch current parents so we can remove them
        $current       = $this->service->files->get($fileId, ['fields' => 'parents']);
        $removeParents = implode(',', $current->getParents() ?? []);

        $metadata = new DriveFile(['name' => $name]);

        $moved = $this->service->files->update($fileId, $metadata, [
            'addParents'    => $targetFolderId,
            'removeParents' => $removeParents,
            'fields'        => self::FILE_FIELDS,
        ]);

        return $this->toCloudFile($moved);
    }

    public function refreshToken(): array
    {
        if (! $this->refreshToken) {
            throw new RuntimeException('No refresh token available for Google Drive.');
        }

        $token = $this->client->fetchAccessTokenWithRefreshToken($this->refreshToken);

        if (isset($token['error'])) {
            throw new RuntimeException("Google Drive token refresh failed: {$token['error_description']}");
        }

        $this->accessToken = $token['access_token'];

        return [
            'access_token' => $token['access_token'],
            'expires_at'   => Carbon::now()->addSeconds((int) ($token['expires_in'] ?? 3600)),
        ];
    }

    private function buildClient(): Client
    {
        $client = new Client();

        if ($this->clientId) {
            $client->setClientId($this->clientId);
        }
        if ($this->clientSecret) {
            $client->setClientSecret($this->clientSecret);
        }

        // google/apiclient accepts the token as a string or array
        $client->setAccessToken($this->accessToken);

        return $client;
    }

    private function toCloudFile(DriveFile $file): CloudFile
    {
        return new CloudFile(
            id:           $file->getId(),
            name:         $file->getName(),
            isFolder:     $file->getMimeType() === self::FOLDER_MIME,
            size:         $file->getSize() !== null ? (int) $file->getSize() : null,
            mimeType:     $file->getMimeType(),
            webViewLink:  $file->getWebViewLink(),
            thumbnailUrl: $file->getThumbnailLink(),
            modifiedAt:   $file->getModifiedTime()
                ? Carbon::parse($file->getModifiedTime())
                : null,
            parentId:     $file->getParents()[0] ?? null,
        );
    }
}
