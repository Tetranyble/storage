<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;

/**
 * Wraps the Microsoft Graph v1.0 REST API behind the CloudAdapter contract.
 *
 * Token refresh uses the Microsoft identity platform OAuth endpoint.
 */
class OneDriveAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const MS_SCOPES = 'https://graph.microsoft.com/Files.ReadWrite.All offline_access';

    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    public function __construct(
        private string $accessToken,
        private ?string $refreshToken,
        private ?string $clientId,
        private ?string $clientSecret,
        private string $tenantId = 'common',
        /** '/me/drive' or '/drives/{driveId}' */
        private string $drivePath = '/me/drive',
    ) {}

    public function listFolder(string $folderId = 'root'): array
    {
        $path = $folderId === 'root'
            ? "{$this->drivePath}/root/children"
            : "{$this->drivePath}/items/{$folderId}/children";

        $items = [];
        $url = self::GRAPH_URL.$path;
        $query = [
            '$select' => 'id,name,file,folder,size,webUrl,lastModifiedDateTime,parentReference',
            '$top' => 1000,
        ];

        // Page through all results
        while ($url) {
            $response = $this->graphRequest('GET', $url, $query);

            foreach ($response->json('value', []) as $item) {
                $items[] = $this->toCloudFile($item);
            }

            $url = $response->json('@odata.nextLink');
            $query = [];
        }

        return $items;
    }

    public function getFileBinary(string $fileId): string
    {
        // Request the @microsoft.graph.downloadUrl pre-auth property, then
        // download without the access token — avoids Graph SDK binary-stream quirks.
        $response = $this->graphRequest(
            'GET',
            self::GRAPH_URL."{$this->drivePath}/items/{$fileId}",
            ['$select' => 'id,@microsoft.graph.downloadUrl'],
        );
        $downloadUrl = $response->json()['@microsoft.graph.downloadUrl'] ?? null;

        if (! $downloadUrl) {
            throw new RuntimeException("Could not obtain download URL for OneDrive item {$fileId}.");
        }

        $response = Http::get($downloadUrl);

        if ($response->failed()) {
            throw new RuntimeException("OneDrive file download failed: HTTP {$response->status()}");
        }

        return $response->body();
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $encodedName = rawurlencode($name);
        $path = $folderId === 'root'
            ? "{$this->drivePath}/root:/{$encodedName}:/content"
            : "{$this->drivePath}/items/{$folderId}:/{$encodedName}:/content";

        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->withBody($binary, $mimeType)
            ->put(self::GRAPH_URL.$path.'?@microsoft.graph.conflictBehavior=rename&$select=id,name,file,size,webUrl,lastModifiedDateTime,parentReference');
        $this->ensureGraphRequestSucceeded($response, 'upload file');

        return $this->toCloudFile($response->json());
    }

    public function deleteFile(string $fileId): void
    {
        $response = Http::withToken($this->accessToken)
            ->acceptJson()
            ->delete(self::GRAPH_URL."{$this->drivePath}/items/{$fileId}");

        if ($response->status() !== 404) {
            $this->ensureGraphRequestSucceeded($response, 'delete file');
        }
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $parentId === 'root'
            ? "{$this->drivePath}/root/children"
            : "{$this->drivePath}/items/{$parentId}/children";

        $response = $this->graphRequest(
            'POST',
            self::GRAPH_URL.$path,
            [
                'name' => $name,
                'folder' => new \stdClass,
                '@microsoft.graph.conflictBehavior' => 'rename',
            ],
        );

        return $this->toCloudFile($response->json());
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $response = $this->graphRequest(
            'GET',
            self::GRAPH_URL."{$this->drivePath}/items/{$fileId}",
            ['$select' => 'id,name,file,folder,size,webUrl,lastModifiedDateTime,parentReference'],
        );

        return $this->toCloudFile($response->json());
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        // OneDrive's native /copy is asynchronous (returns 202). To keep the API
        // synchronous we download+re-upload within the same drive. For large files
        // callers should instead enqueue a background job.
        $binary = $this->getFileBinary($fileId);
        $meta = $this->getMetadata($fileId);
        $mimeType = $meta->mimeType ?? 'application/octet-stream';

        return $this->putFile($targetFolderId, $name, $binary, $mimeType);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $response = $this->graphRequest(
            'PATCH',
            self::GRAPH_URL."{$this->drivePath}/items/{$fileId}",
            ['parentReference' => ['id' => $targetFolderId], 'name' => $name],
        );

        return $this->toCloudFile($response->json());
    }

    public function refreshToken(): array
    {
        if (! $this->refreshToken || ! $this->clientId || ! $this->clientSecret) {
            throw new RuntimeException('Missing credentials for OneDrive token refresh.');
        }

        $url = sprintf(self::TOKEN_URL, $this->tenantId);

        $response = Http::asForm()->post($url, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => self::MS_SCOPES,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("OneDrive token refresh failed: {$response->body()}");
        }

        $data = $response->json();

        $this->accessToken = $data['access_token'];
        if (isset($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
        }

        $result = ['access_token' => $data['access_token'], 'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600))];
        if (isset($data['refresh_token'])) {
            $result['refresh_token'] = $data['refresh_token'];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function graphRequest(string $method, string $url, array $data = []): Response
    {
        $request = Http::withToken($this->accessToken)->acceptJson();
        $response = strtoupper($method) === 'GET'
            ? $request->get($url, $data)
            : $request->send($method, $url, ['json' => $data]);

        $this->ensureGraphRequestSucceeded($response, strtolower($method).' request');

        return $response;
    }

    private function ensureGraphRequestSucceeded(Response $response, string $operation): void
    {
        if ($response->failed()) {
            throw new RuntimeException("OneDrive {$operation} failed: HTTP {$response->status()} {$response->body()}");
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function toCloudFile(array $item): CloudFile
    {
        $isFolder = array_key_exists('folder', $item);
        $size = $isFolder ? null : ($item['size'] ?? null);

        return new CloudFile(
            id: (string) $item['id'],
            name: (string) $item['name'],
            isFolder: $isFolder,
            size: $size !== null ? (int) $size : null,
            mimeType: $item['file']['mimeType'] ?? null,
            webViewLink: $item['webUrl'] ?? null,
            thumbnailUrl: $item['thumbnails'][0]['medium']['url'] ?? null,
            modifiedAt: isset($item['lastModifiedDateTime'])
                ? Carbon::parse($item['lastModifiedDateTime'])
                : null,
            parentId: $item['parentReference']['id'] ?? null,
        );
    }
}
