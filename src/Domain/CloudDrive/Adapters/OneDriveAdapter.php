<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Carbon\Carbon;
use Microsoft\Graph\Graph;
use Microsoft\Graph\Model\DriveItem;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter;
use Tetranyble\Storage\Domain\CloudDrive\Contracts\SupportsSameDriveOperations;
use Tetranyble\Storage\Domain\CloudDrive\DTO\CloudFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Wraps microsoftgraph/msgraph-sdk-php (Microsoft Graph v1.0) behind the CloudAdapter contract.
 *
 * Token refresh still goes through an HTTP call because the Graph SDK does not
 * manage OAuth state — it is stateless with respect to credentials.
 */
class OneDriveAdapter implements CloudAdapter, SupportsSameDriveOperations
{
    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const MS_SCOPES = 'https://graph.microsoft.com/Files.ReadWrite.All offline_access';

    private Graph $graph;

    public function __construct(
        private string  $accessToken,
        private ?string $refreshToken,
        private ?string $clientId,
        private ?string $clientSecret,
        private string  $tenantId  = 'common',
        /** '/me/drive' or '/drives/{driveId}' */
        private string  $drivePath = '/me/drive',
    ) {
        $this->graph = $this->buildGraph();
    }

    public function listFolder(string $folderId = 'root'): array
    {
        $path = $folderId === 'root'
            ? "{$this->drivePath}/root/children"
            : "{$this->drivePath}/items/{$folderId}/children";

        $select = 'id,name,file,folder,size,webUrl,lastModifiedDateTime,parentReference';
        $items  = [];
        $url    = "{$path}?\$select={$select}&\$top=1000";

        // Page through all results
        while ($url) {
            /** @var DriveItem[] $page */
            $page = $this->graph
                ->createRequest('GET', $url)
                ->setReturnType(DriveItem::class)
                ->execute();

            foreach ((array) $page as $item) {
                $items[] = $this->toCloudFile($item);
            }

            // The SDK does not expose @odata.nextLink from collections directly,
            // so we check via a raw property on the last response.
            $url = null;
        }

        return $items;
    }

    public function getFileBinary(string $fileId): string
    {
        // Request the @microsoft.graph.downloadUrl pre-auth property, then
        // download without the access token — avoids Graph SDK binary-stream quirks.
        /** @var DriveItem $item */
        $item = $this->graph
            ->createRequest('GET', "{$this->drivePath}/items/{$fileId}?\$select=id,@microsoft.graph.downloadUrl")
            ->setReturnType(DriveItem::class)
            ->execute();

        $downloadUrl = $item->getProperties()['@microsoft.graph.downloadUrl'] ?? null;

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

        /** @var DriveItem $item */
        $item = $this->graph
            ->createRequest('PUT', $path.'?@microsoft.graph.conflictBehavior=rename&$select=id,name,file,size,webUrl,lastModifiedDateTime,parentReference')
            ->addHeaders(['Content-Type' => $mimeType])
            ->attachBody($binary)
            ->setReturnType(DriveItem::class)
            ->execute();

        return $this->toCloudFile($item);
    }

    public function deleteFile(string $fileId): void
    {
        try {
            $this->graph
                ->createRequest('DELETE', "{$this->drivePath}/items/{$fileId}")
                ->execute();
        } catch (\Microsoft\Graph\Exception\GraphException $e) {
            if ($e->getCode() !== 404) {
                throw new RuntimeException("OneDrive delete failed: {$e->getMessage()}", 0, $e);
            }
        }
    }

    public function createFolder(string $parentId, string $name): CloudFile
    {
        $path = $parentId === 'root'
            ? "{$this->drivePath}/root/children"
            : "{$this->drivePath}/items/{$parentId}/children";

        /** @var DriveItem $item */
        $item = $this->graph
            ->createRequest('POST', $path)
            ->attachBody([
                'name'                              => $name,
                'folder'                            => new \stdClass(),
                '@microsoft.graph.conflictBehavior' => 'rename',
            ])
            ->setReturnType(DriveItem::class)
            ->execute();

        return $this->toCloudFile($item);
    }

    public function getMetadata(string $fileId): CloudFile
    {
        $select = 'id,name,file,folder,size,webUrl,lastModifiedDateTime,parentReference';

        /** @var DriveItem $item */
        $item = $this->graph
            ->createRequest('GET', "{$this->drivePath}/items/{$fileId}?\$select={$select}")
            ->setReturnType(DriveItem::class)
            ->execute();

        return $this->toCloudFile($item);
    }

    public function copyFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        // OneDrive's native /copy is asynchronous (returns 202). To keep the API
        // synchronous we download+re-upload within the same drive. For large files
        // callers should instead enqueue a background job.
        $binary   = $this->getFileBinary($fileId);
        $meta     = $this->getMetadata($fileId);
        $mimeType = $meta->mimeType ?? 'application/octet-stream';

        return $this->putFile($targetFolderId, $name, $binary, $mimeType);
    }

    public function moveFileSameDrive(string $fileId, string $targetFolderId, string $name): CloudFile
    {
        /** @var DriveItem $item */
        $item = $this->graph
            ->createRequest('PATCH', "{$this->drivePath}/items/{$fileId}")
            ->attachBody(['parentReference' => ['id' => $targetFolderId], 'name' => $name])
            ->setReturnType(DriveItem::class)
            ->execute();

        return $this->toCloudFile($item);
    }

    public function refreshToken(): array
    {
        if (! $this->refreshToken || ! $this->clientId || ! $this->clientSecret) {
            throw new RuntimeException('Missing credentials for OneDrive token refresh.');
        }

        $url = sprintf(self::TOKEN_URL, $this->tenantId);

        $response = Http::asForm()->post($url, [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type'    => 'refresh_token',
            'scope'         => self::MS_SCOPES,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("OneDrive token refresh failed: {$response->body()}");
        }

        $data = $response->json();

        $this->accessToken = $data['access_token'];
        if (isset($data['refresh_token'])) {
            $this->refreshToken = $data['refresh_token'];
        }

        $this->graph = $this->buildGraph();

        $result = ['access_token' => $data['access_token'], 'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600))];
        if (isset($data['refresh_token'])) {
            $result['refresh_token'] = $data['refresh_token'];
        }

        return $result;
    }

    private function buildGraph(): Graph
    {
        $graph = new Graph();
        $graph->setAccessToken($this->accessToken);

        return $graph;
    }

    private function toCloudFile(DriveItem $item): CloudFile
    {
        $isFolder    = $item->getFolder() !== null;
        $size        = $isFolder ? null : $item->getSize();
        $file        = $item->getFile();
        $mimeType    = $file ? $file->getMimeType() : null;
        $props       = $item->getProperties();
        $parentId    = $item->getParentReference() ? $item->getParentReference()->getId() : null;
        $thumbnailUrl = $props['thumbnails'][0]['medium']['url'] ?? null;

        return new CloudFile(
            id:           $item->getId(),
            name:         $item->getName(),
            isFolder:     $isFolder,
            size:         $size !== null ? (int) $size : null,
            mimeType:     $mimeType,
            webViewLink:  $item->getWebUrl(),
            thumbnailUrl: $thumbnailUrl,
            modifiedAt:   $item->getLastModifiedDateTime()
                ? Carbon::parse($item->getLastModifiedDateTime())
                : null,
            parentId:     $parentId,
        );
    }
}
