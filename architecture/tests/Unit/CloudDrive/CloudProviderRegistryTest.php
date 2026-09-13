<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Mockery;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Exceptions\MissingCloudProviderDependency;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\CloudProviderDependencyGuard;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderRegistry;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderStrategy;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\DefaultCloudProviderRegistryFactory;

class CloudProviderRegistryTest extends TestCase
{
    public function test_registered_strategy_owns_adapter_resolution_and_credential_preparation(): void
    {
        $adapter = Mockery::mock(CloudAdapter::class);
        $strategy = new StubCloudProviderStrategy(CloudProvider::LOCAL, $adapter);
        $registry = new CloudProviderRegistry(new CloudProviderDependencyGuard, [$strategy]);
        $drive = new ConnectedDrive(['provider' => CloudProvider::LOCAL]);

        $this->assertSame($adapter, $registry->adapterFor($drive));

        $registry->prepareCredentials(CloudProvider::LOCAL, ['disk' => 'private']);

        $this->assertSame([['disk' => 'private']], $strategy->preparedCredentials);
        $this->assertSame(['local'], $registry->registeredProviders());
    }

    public function test_registration_can_replace_a_provider_strategy_without_changing_orchestrator(): void
    {
        $first = new StubCloudProviderStrategy(CloudProvider::LOCAL, Mockery::mock(CloudAdapter::class));
        $replacementAdapter = Mockery::mock(CloudAdapter::class);
        $replacement = new StubCloudProviderStrategy(CloudProvider::LOCAL, $replacementAdapter);
        $registry = new CloudProviderRegistry(new CloudProviderDependencyGuard, [$first]);

        $registry->register($replacement);

        $this->assertSame(
            $replacementAdapter,
            $registry->adapterFor(new ConnectedDrive(['provider' => CloudProvider::LOCAL])),
        );
    }

    public function test_dependency_requirements_come_from_strategy_not_central_orchestrator(): void
    {
        $guard = new CloudProviderDependencyGuard(static fn (string $class): bool => $class !== 'Vendor\\MissingSdk');
        $strategy = new StubCloudProviderStrategy(
            CloudProvider::LOCAL,
            Mockery::mock(CloudAdapter::class),
            ['vendor/missing-sdk' => 'Vendor\\MissingSdk'],
        );
        $registry = new CloudProviderRegistry($guard, [$strategy]);

        $this->expectException(MissingCloudProviderDependency::class);
        $registry->prepareCredentials(CloudProvider::LOCAL, []);
    }

    public function test_registry_reports_oauth_capability_from_strategy_type(): void
    {
        $registry = DefaultCloudProviderRegistryFactory::make(
            new CloudProviderDependencyGuard,
            [],
        );

        $this->assertTrue($registry->isOAuth(CloudProvider::GOOGLE_DRIVE));
        $this->assertTrue($registry->isOAuth(CloudProvider::ONEDRIVE));
        $this->assertTrue($registry->isOAuth(CloudProvider::DROPBOX));
        $this->assertFalse($registry->isOAuth(CloudProvider::S3));
    }

    public function test_unregistered_provider_fails_explicitly(): void
    {
        $registry = new CloudProviderRegistry(new CloudProviderDependencyGuard);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No cloud provider strategy is registered');
        $registry->strategy(CloudProvider::S3);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

final class StubCloudProviderStrategy implements CloudProviderStrategy
{
    /** @var list<array<string, mixed>> */
    public array $preparedCredentials = [];

    /** @param array<string, class-string> $requirements */
    public function __construct(
        private readonly CloudProvider $cloudProvider,
        private readonly CloudAdapter $cloudAdapter,
        private readonly array $requirements = [],
    ) {}

    public function provider(): CloudProvider
    {
        return $this->cloudProvider;
    }

    public function packageRequirements(): array
    {
        return $this->requirements;
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        return $this->cloudAdapter;
    }

    public function prepareCredentials(array $credentials): void
    {
        $this->preparedCredentials[] = $credentials;
    }
}
