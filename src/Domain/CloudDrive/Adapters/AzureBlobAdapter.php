<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use AzureOss\Storage\Blob\BlobServiceClient;
use AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;

class AzureBlobAdapter extends AbstractFlysystemAdapter
{
    public function __construct(string $connectionString, string $container)
    {
        $client = BlobServiceClient::fromConnectionString($connectionString);
        $adapter = new AzureBlobStorageAdapter($client->getContainerClient($container));
        $this->disk = new Filesystem($adapter);
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

        return new static($connectionString, $container);
    }
}
