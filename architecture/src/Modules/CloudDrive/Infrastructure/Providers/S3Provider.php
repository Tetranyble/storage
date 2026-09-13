<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\S3Adapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class S3Provider implements CloudProviderStrategy
{
    public function provider(): CloudProvider
    {
        return CloudProvider::S3;
    }

    public function packageRequirements(): array
    {
        return ['league/flysystem-aws-s3-v3' => AwsS3V3Adapter::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        return $this->fromCredentials($drive->credentials ?? []);
    }

    public function prepareCredentials(array $credentials): void
    {
        foreach (['bucket', 'key', 'secret', 'region'] as $field) {
            if (empty($credentials[$field])) {
                throw new RuntimeException("S3 credentials must include '{$field}'.");
            }
        }

        $this->fromCredentials($credentials)->listFolder('root');
    }

    /** @param array<string, mixed> $credentials */
    private function fromCredentials(array $credentials): S3Adapter
    {
        return new S3Adapter(
            bucket: $credentials['bucket'] ?? '',
            key: $credentials['key'] ?? '',
            secret: $credentials['secret'] ?? '',
            region: $credentials['region'] ?? 'us-east-1',
            url: $credentials['url'] ?? '',
            endpoint: $credentials['endpoint'] ?? '',
        );
    }
}
