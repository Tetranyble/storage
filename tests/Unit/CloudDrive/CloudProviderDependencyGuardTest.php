<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use PHPUnit\Framework\TestCase;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Exceptions\MissingCloudProviderDependency;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\CloudProviderDependencyGuard;

class CloudProviderDependencyGuardTest extends TestCase
{
    public function test_core_and_onedrive_providers_do_not_require_optional_packages(): void
    {
        $guard = new CloudProviderDependencyGuard(static fn (): bool => false);

        $this->assertSame([], $guard->missingPackages(CloudProvider::LOCAL));
        $this->assertSame([], $guard->missingPackages(CloudProvider::ONEDRIVE));
    }

    public function test_optional_provider_reports_the_exact_missing_package(): void
    {
        $guard = new CloudProviderDependencyGuard(static fn (): bool => false);

        $this->assertSame(
            ['league/flysystem-aws-s3-v3'],
            $guard->missingPackages(CloudProvider::S3),
        );
        $this->assertSame(
            ['azure-oss/storage-blob-flysystem'],
            $guard->missingPackages(CloudProvider::AZURE_BLOB),
        );
    }

    public function test_missing_provider_dependency_fails_with_actionable_exception(): void
    {
        $guard = new CloudProviderDependencyGuard(static fn (): bool => false);

        try {
            $guard->assertAvailable(CloudProvider::GOOGLE_DRIVE);
            $this->fail('Missing optional provider dependency should fail fast.');
        } catch (MissingCloudProviderDependency $exception) {
            $this->assertSame(CloudProvider::GOOGLE_DRIVE, $exception->provider);
            $this->assertSame(['google/apiclient'], $exception->packages);
            $this->assertStringContainsString('google/apiclient', $exception->getMessage());
        }
    }
}
