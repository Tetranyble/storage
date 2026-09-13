<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\AzureBlobAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class AzureBlobProvider implements CloudProviderStrategy
{
    public function provider(): CloudProvider
    {
        return CloudProvider::AZURE_BLOB;
    }

    public function packageRequirements(): array
    {
        return ['azure-oss/storage-blob-flysystem' => \AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        $credentials = $drive->credentials ?? [];
        $container = $credentials['container'] ?? '';

        if (! empty($credentials['connection_string'])) {
            return new AzureBlobAdapter($credentials['connection_string'], $container);
        }

        return AzureBlobAdapter::fromCredentials(
            accountName: $credentials['account_name'] ?? '',
            accountKey: $credentials['account_key'] ?? '',
            container: $container,
        );
    }

    public function prepareCredentials(array $credentials): void
    {
        if (empty($credentials['container'])) {
            throw new RuntimeException("Azure Blob credentials must include 'container'.");
        }

        if (empty($credentials['connection_string'])
            && (empty($credentials['account_name']) || empty($credentials['account_key']))) {
            throw new RuntimeException("Azure Blob credentials must include 'connection_string' or 'account_name'+'account_key'.");
        }
    }
}
