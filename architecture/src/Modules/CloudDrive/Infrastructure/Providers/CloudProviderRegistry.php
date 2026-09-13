<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\CloudProviderDependencyGuard;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final class CloudProviderRegistry
{
    /** @var array<string, CloudProviderStrategy> */
    private array $strategies = [];

    /** @param iterable<CloudProviderStrategy> $strategies */
    public function __construct(
        private readonly CloudProviderDependencyGuard $dependencies,
        iterable $strategies = [],
    ) {
        foreach ($strategies as $strategy) {
            $this->register($strategy);
        }
    }

    public function register(CloudProviderStrategy $strategy): void
    {
        $this->strategies[$strategy->provider()->value] = $strategy;
    }

    public function assertAvailable(CloudProvider $provider): void
    {
        $strategy = $this->strategy($provider);
        $this->dependencies->assertRequirements($provider, $strategy->packageRequirements());
    }

    public function adapterFor(ConnectedDrive $drive): CloudAdapter
    {
        $this->assertAvailable($drive->provider);

        return $this->strategy($drive->provider)->adapter($drive);
    }

    /** @param array<string, mixed> $credentials */
    public function prepareCredentials(CloudProvider $provider, array $credentials): void
    {
        $this->assertAvailable($provider);
        $this->strategy($provider)->prepareCredentials($credentials);
    }

    public function strategy(CloudProvider $provider): CloudProviderStrategy
    {
        return $this->strategies[$provider->value]
            ?? throw new RuntimeException("No cloud provider strategy is registered for {$provider->value}.");
    }

    public function oauthStrategy(CloudProvider $provider): OAuthCloudProviderStrategy
    {
        $strategy = $this->strategy($provider);

        if (! $strategy instanceof OAuthCloudProviderStrategy) {
            throw new RuntimeException("{$provider->label()} does not use OAuth.");
        }

        return $strategy;
    }

    public function isOAuth(CloudProvider $provider): bool
    {
        return $this->strategy($provider) instanceof OAuthCloudProviderStrategy;
    }

    /** @return list<string> */
    public function registeredProviders(): array
    {
        return array_keys($this->strategies);
    }
}
