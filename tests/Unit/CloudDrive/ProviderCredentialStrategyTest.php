<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\AzureBlobProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderStrategy;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudinaryProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\GcsProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\S3Provider;

class ProviderCredentialStrategyTest extends TestCase
{
    #[DataProvider('invalidCredentials')]
    public function test_provider_strategy_rejects_invalid_credentials(CloudProviderStrategy $provider, array $credentials, string $message): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage($message);

        $provider->prepareCredentials($credentials);
    }

    /** @return iterable<string, array{CloudProviderStrategy, array<string, mixed>, string}> */
    public static function invalidCredentials(): iterable
    {
        yield 's3' => [new S3Provider(), [], "S3 credentials must include 'bucket'"];
        yield 'azure' => [new AzureBlobProvider(), [], "Azure Blob credentials must include 'container'"];
        yield 'gcs' => [new GcsProvider(), [], "GCS credentials must include 'key_file'"];
        yield 'cloudinary' => [new CloudinaryProvider(), [], "Cloudinary credentials must include 'cloud_name'"];
    }
}
