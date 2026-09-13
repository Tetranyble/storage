<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters;

use Carbon\Carbon;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Modules\CloudDrive\Domain\DTO\CloudFile;

/**
 * Microsoft OneDrive adapter implemented directly against Microsoft Graph v1.0.
 *
 * The package deliberately avoids coupling this adapter to a major version of
 * Microsoft's generated PHP SDK. Graph authentication remains token-based and
 * OAuth refresh is handled through the standard v2 token endpoint.
 */
class OneDriveAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private const GRAPH_URL = 'https://graph.microsoft.com/v1.0';

    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const MS_SCOPES = 'https://graph.microsoft.com/Files.ReadWrite.All offline_access';

    private const ITEM_SELECT = 'id,name,file,folder,size,webUrl,lastModifiedDateTime,parentReference';

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

        $url = $this->graphUrl($path);
        $items = [];
        $query = ['$select' => self::ITEM_SELECT, '$top' => 200];
        $visitedUrls = [];

        while ($url !== null) {
            if (isset($visitedUrls[$url])) {
                throw new RuntimeException("Unable to list OneDrive folder: Microsoft Graph returned a repeated pagination URL: {$url}");
            }
            $visitedUrls[$url] = true;

            // Passing an empty `query` option makes Guzzle replace the query
            // string already embedded in Graph's absolute nextLink URL.
            $response = $query === []
                ? $this->graph()->get($url)
                : $this->graph()->get($url, $query);
            $data = $this->jsonOrFail($response, 'list OneDrive folder');

            foreach (($data['value'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $this->toCloudFile($item);
                }
            }

            $url = isset($data['@odata.nextLink']) && is_string($data['@odata.nextLink'])
                ? $data['@odata.nextLink']
                : null;
            $query = [];
        }

        return $items;
    }

    public function getFileBinary(string $fileId): string
    {
        $response = $this->graph()->get(
            $this->graphUrl("{$this->drivePath}/items/{$fileId}"),
        );
        $item = $this->jsonOrFail($response, 'resolve OneDrive download URL');
        $downloadUrl = $item['@microsoft.graph.downloadUrl'] ?? null;

        if (! is_string($downloadUrl) || $downloadUrl === '') {
            throw new RuntimeException("Could not obtain download URL for OneDrive item {$fileId}.");
        }

        $download = Http::timeout(60)->get($downloadUrl);
        if ($download->failed()) {
            throw new RuntimeException("OneDrive file download failed: HTTP {$download->status()}");
        }

        return $download->body();
    }

    public function putFile(string $folderId, string $name, string $binary, string $mimeType = 'application/octet-stream'): CloudFile
    {
        $encodedName = rawurlencode($name);
        $path = $folderId === 'root'
            ? "{$this->drivePath}/root:/{$encodedName}:/content"
            : "{$this->drivePath}/items/{$folderId}:/{$encodedName}:/content";

        $response = $this->graph()
            ->withBody($binary, $mimeType)
            ->put($this->graphUrl($path).'?@microsoft.graph.conflictBehavior=rename&$select='.rawurlencode(self::ITEM_SELECT));

        return $this->toCloudFile($this->jsonOrFail($response, 'upload OneDrive file'));
    }

    public function deleteFile(string $fileId): void
    {
        $response = $this->graph()->delete($this->graphUrl("{$this->drivePath}/items/{$fileId}"));

        if ($response->status() === 404) {
            return;
        }

        $this->assertSuccessful($response, 'delete OneDrive file');
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $parentId === 'root'
            ? "{$this->drivePath}/root/children"
            : "{$this->drivePath}/items/{$parentId}/children";

        $response = $this->graph()->post($this->graphUrl($path), [
            'name' => $name,
            'folder' => new \stdClass,
            '@microsoft.graph.conflictBehavior' => 'rename',
        ]);

        return $this->toCloudFile($this->jsonOrFail($response, 'create OneDrive folder'));
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $response = $this->graph()->get(
            $this->graphUrl("{$this->drivePath}/items/{$fileId}"),
            ['$select' => self::ITEM_SELECT],
        );

        return $this->toCloudFile($this->jsonOrFail($response, 'read OneDrive metadata'));
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        // Graph's native /copy endpoint is asynchronous. Preserve the package's
        // synchronous contract by downloading and re-uploading the object.
        $binary = $this->getFileBinary($fileId);
        $metadata = $this->getMetadata($fileId);

        return $this->putFile(
            $targetFolderId,
            $name,
            $binary,
            $metadata->mimeType ?? 'application/octet-stream',
        );
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        $parentId = $targetFolderId;
        if ($targetFolderId === 'root') {
            $root = $this->graph()->get(
                $this->graphUrl("{$this->drivePath}/root"),
                ['$select' => 'id'],
            );
            $rootData = $this->jsonOrFail($root, 'resolve OneDrive root folder');
            $parentId = (string) ($rootData['id'] ?? '');
        }

        $response = $this->graph()->patch($this->graphUrl("{$this->drivePath}/items/{$fileId}"), [
            'parentReference' => ['id' => $parentId],
            'name' => $name,
        ]);

        return $this->toCloudFile($this->jsonOrFail($response, 'move OneDrive file'));
    }

    public function refreshToken(): array
    {
        if (! $this->refreshToken || ! $this->clientId || ! $this->clientSecret) {
            throw new RuntimeException('Missing credentials for OneDrive token refresh.');
        }

        $response = Http::asForm()->timeout(30)->post(sprintf(self::TOKEN_URL, $this->tenantId), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token',
            'scope' => self::MS_SCOPES,
        ]);

        $data = $this->jsonOrFail($response, 'refresh OneDrive token');
        $accessToken = $data['access_token'] ?? null;
        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('OneDrive token refresh response did not include an access token.');
        }

        $this->accessToken = $accessToken;
        if (isset($data['refresh_token']) && is_string($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
        }

        $result = [
            'access_token' => $accessToken,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
        if ($this->refreshToken !== null) {
            $result['refresh_token'] = $this->refreshToken;
        }

        return $result;
    }

    private function graph(): PendingRequest
    {
        return Http::withToken($this->accessToken)
            ->acceptJson()
            ->timeout(60);
    }

    private function graphUrl(string $path): string
    {
        return self::GRAPH_URL.'/'.ltrim($path, '/');
    }

    /** @return array<string, mixed> */
    private function jsonOrFail(Response $response, string $operation): array
    {
        $this->assertSuccessful($response, $operation);
        $data = $response->json();

        if (! is_array($data)) {
            throw new RuntimeException("Unable to {$operation}: Microsoft Graph returned invalid JSON.");
        }

        return $data;
    }

    private function assertSuccessful(Response $response, string $operation): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('error.message');
        if (! is_string($message) || $message === '') {
            $message = trim($response->body());
        }

        throw new RuntimeException(sprintf(
            'Unable to %s: Microsoft Graph HTTP %d%s',
            $operation,
            $response->status(),
            $message !== '' ? ' - '.$message : '',
        ));
    }

    /** @param array<string, mixed> $item */
    private function toCloudFile(array $item): CloudFile
    {
        $isFolder = array_key_exists('folder', $item);
        $mimeType = is_array($item['file'] ?? null) ? ($item['file']['mimeType'] ?? null) : null;
        $parentId = is_array($item['parentReference'] ?? null) ? ($item['parentReference']['id'] ?? null) : null;

        return new CloudFile(
            id: (string) ($item['id'] ?? ''),
            name: (string) ($item['name'] ?? ''),
            isFolder: $isFolder,
            size: $isFolder || ! isset($item['size']) ? null : (int) $item['size'],
            mimeType: is_string($mimeType) ? $mimeType : null,
            webViewLink: isset($item['webUrl']) && is_string($item['webUrl']) ? $item['webUrl'] : null,
            thumbnailUrl: null,
            modifiedAt: isset($item['lastModifiedDateTime']) && is_string($item['lastModifiedDateTime'])
                ? Carbon::parse($item['lastModifiedDateTime'])
                : null,
            parentId: is_string($parentId) ? $parentId : null,
        );
    }
}
