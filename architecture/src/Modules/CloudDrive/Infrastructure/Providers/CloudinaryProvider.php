<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\CloudinaryAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class CloudinaryProvider implements CloudProviderStrategy
{
    public function provider(): CloudProvider
    {
        return CloudProvider::CLOUDINARY;
    }

    public function packageRequirements(): array
    {
        return ['cloudinary/cloudinary_php' => \Cloudinary\Cloudinary::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        $credentials = $drive->credentials ?? [];

        return new CloudinaryAdapter(
            cloudName: $credentials['cloud_name'] ?? '',
            apiKey: $credentials['api_key'] ?? '',
            apiSecret: $credentials['api_secret'] ?? '',
        );
    }

    public function prepareCredentials(array $credentials): void
    {
        foreach (['cloud_name', 'api_key', 'api_secret'] as $field) {
            if (empty($credentials[$field])) {
                throw new RuntimeException("Cloudinary credentials must include '{$field}'.");
            }
        }
    }
}
