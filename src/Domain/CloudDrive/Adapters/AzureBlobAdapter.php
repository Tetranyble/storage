<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use League\Flysystem\AzureBlobStorage\AzureBlobStorageAdapter;
use League\Flysystem\Filesystem;
use MicrosoftAzure\Storage\Blob\BlobRestProxy;

class AzureBlobAdapter extends AbstractFlysystemAdapter
{
    public function __construct(string $connectionString, string $container)
    {
        $client      = BlobRestProxy::createBlobService($connectionString);
        $adapter     = new AzureBlobStorageAdapter($client, $container);
        $this->disk  = new Filesystem($adapter);
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
