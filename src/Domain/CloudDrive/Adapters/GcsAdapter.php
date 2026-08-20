<?php

namespace Tetranyble\Storage\Domain\CloudDrive\Adapters;

use Google\Cloud\Storage\StorageClient;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter;
use League\Flysystem\Filesystem;

class GcsAdapter extends AbstractFlysystemAdapter
{
    public function __construct(array $keyFile, string $bucket, string $pathPrefix = '')
    {
        $storageClient = new StorageClient(['keyFile' => $keyFile]);
        $gcsBucket     = $storageClient->bucket($bucket);
        $adapter       = new GoogleCloudStorageAdapter($gcsBucket, $pathPrefix);
        $this->disk    = new Filesystem($adapter);
    }

    /**
     * Instantiate from a JSON key file path on disk.
     */
    public static function fromKeyFilePath(string $keyFilePath, string $bucket, string $pathPrefix = ''): static
    {
        $keyFile = json_decode(file_get_contents($keyFilePath), true, 512, JSON_THROW_ON_ERROR);

        return new static($keyFile, $bucket, $pathPrefix);
    }
}
