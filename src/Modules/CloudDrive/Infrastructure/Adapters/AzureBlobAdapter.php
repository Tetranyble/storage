<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;

final class AzureBlobAdapter extends AbstractFlysystemAdapter
{
    public function __construct(string $connectionString, string $container)
    {
        $service = BlobServiceClient::fromConnectionString($connectionString);
        $containerClient = $service->getContainerClient($container);
        $this->disk = new Filesystem(new AzureBlobStorageAdapter($containerClient));
    }

    /**
     * Build a connection string from individual account components
     * when a full connection string is not available.
     */
    public static function fromCredentials(
        string $accountName,
        string $accountKey,
        string $container,
        string $endpointSuffix = 'core.windows.net',
    ): static {
        $connectionString = implode(';', [
            'DefaultEndpointsProtocol=https',
            "AccountName={$accountName}",
            "AccountKey={$accountKey}",
            "EndpointSuffix={$endpointSuffix}",
        ]);

        return new self($connectionString, $container);
    }
}
