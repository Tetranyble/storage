<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\GcsAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class GcsProvider implements CloudProviderStrategy
{
    public function provider(): CloudProvider
    {
        return CloudProvider::GCS;
    }

    public function packageRequirements(): array
    {
        return ['league/flysystem-google-cloud-storage' => \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        $credentials = $drive->credentials ?? [];

        return new GcsAdapter(
            keyFile: $credentials['key_file'] ?? [],
            bucket: $credentials['bucket'] ?? '',
            pathPrefix: $credentials['path_prefix'] ?? '',
        );
    }

    public function prepareCredentials(array $credentials): void
    {
        if (empty($credentials['key_file']) || ! is_array($credentials['key_file'])) {
            throw new RuntimeException("GCS credentials must include 'key_file' (decoded JSON key array).");
        }
        if (empty($credentials['bucket'])) {
            throw new RuntimeException("GCS credentials must include 'bucket'.");
        }
    }
}
