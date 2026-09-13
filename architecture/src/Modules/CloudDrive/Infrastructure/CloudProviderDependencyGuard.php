<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure;

use Closure;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Exceptions\MissingCloudProviderDependency;

final class CloudProviderDependencyGuard
{
    /** @var array<string, array<string, class-string>> */
    private const REQUIREMENTS = [
        'google_drive' => [
            'google/apiclient' => \Google\Client::class,
        ],
        'dropbox' => [
            'spatie/dropbox-api' => \Spatie\Dropbox\Client::class,
        ],
        's3' => [
            'league/flysystem-aws-s3-v3' => \League\Flysystem\AwsS3V3\AwsS3V3Adapter::class,
        ],
        'azure_blob' => [
            'azure-oss/storage-blob-flysystem' => \AzureOss\Storage\BlobFlysystem\AzureBlobStorageAdapter::class,
        ],
        'gcs' => [
            'league/flysystem-google-cloud-storage' => \League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter::class,
        ],
        'cloudinary' => [
            'cloudinary/cloudinary_php' => \Cloudinary\Cloudinary::class,
        ],
    ];

    /** @var Closure(class-string): bool */
    private readonly Closure $classExists;

    /** @param null|callable(class-string): bool $classExists */
    public function __construct(?callable $classExists = null)
    {
        $this->classExists = Closure::fromCallable($classExists ?? 'class_exists');
    }

    public function assertAvailable(CloudProvider $provider): void
    {
        $this->assertRequirements($provider, self::REQUIREMENTS[$provider->value] ?? []);
    }

    /** @param array<string, class-string> $requirements */
    public function assertRequirements(CloudProvider $provider, array $requirements): void
    {
        $missing = $this->missingRequirements($requirements);

        if ($missing !== []) {
            throw new MissingCloudProviderDependency($provider, $missing);
        }
    }

    /** @param array<string, class-string> $requirements @return list<string> */
    public function missingRequirements(array $requirements): array
    {
        $missing = [];

        foreach ($requirements as $package => $class) {
            if (! ($this->classExists)($class)) {
                $missing[] = $package;
            }
        }

        return $missing;
    }

    /** @return list<string> */
    public function missingPackages(CloudProvider $provider): array
    {
        return $this->missingRequirements(self::REQUIREMENTS[$provider->value] ?? []);
    }

    /** @return array<string, list<string>> */
    public function packageRequirements(): array
    {
        $result = [];

        foreach (CloudProvider::cases() as $provider) {
            $result[$provider->value] = array_keys(self::REQUIREMENTS[$provider->value] ?? []);
        }

        return $result;
    }
}
