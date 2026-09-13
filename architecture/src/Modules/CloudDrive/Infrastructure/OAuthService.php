<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure;

use Carbon\Carbon;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\CloudProviderRegistry;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers\DefaultCloudProviderRegistryFactory;
use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;
use Throwable;

class OAuthService
{
    private ?CloudProviderRegistry $resolvedProviders = null;

    /** @param array<string, mixed> $config */
    public function __construct(
        private array $config,
        private readonly ?StorageTelemetry $telemetry = null,
        private readonly ?CloudProviderRegistry $providers = null,
    ) {}

    /** Build the OAuth redirect URL. $state should be a CSRF token stored by the host. */
    public function buildAuthUrl(CloudProvider $provider, string $state, array $extraScopes = []): string
    {
        return $this->providerRegistry()->oauthStrategy($provider)->buildAuthUrl($state, $extraScopes);
    }

    /** @return array{access_token: string, refresh_token: string|null, expires_at: Carbon} */
    public function exchangeCode(CloudProvider $provider, string $code): array
    {
        return $this->providerRegistry()->oauthStrategy($provider)->exchangeCode($code);
    }

    /** Refresh an OAuth access token and persist the resulting token set. */
    public function refreshAccessToken(ConnectedDrive $drive): ConnectedDrive
    {
        try {
            $tokens = $this->providerRegistry()->oauthStrategy($drive->provider)->refreshAccessToken($drive);

            $drive->forceFill([
                'access_token' => $tokens['access_token'],
                'token_expires_at' => $tokens['expires_at'],
            ]);
            if (array_key_exists('refresh_token', $tokens) && $tokens['refresh_token'] !== null) {
                $drive->refresh_token = $tokens['refresh_token'];
            }
            $drive->save();

            $this->telemetry?->counter('oauth.refresh.success', 1, [
                'provider' => $drive->provider->value,
            ]);

            return $drive->refresh();
        } catch (Throwable $exception) {
            $provider = $drive->provider instanceof CloudProvider ? $drive->provider->value : (string) $drive->provider;
            $this->telemetry?->counter('oauth.refresh.failures', 1, ['provider' => $provider, 'exception' => $exception::class]);
            $this->telemetry?->event('oauth.refresh_failed', [
                'drive_id' => (int) $drive->getKey(),
                'workspace_id' => $drive->workspace_id !== null ? (int) $drive->workspace_id : null,
                'provider' => $provider,
                'exception' => $exception::class,
            ], TelemetryLevel::ERROR);
            throw $exception;
        }
    }

    private function providerRegistry(): CloudProviderRegistry
    {
        return $this->providers
            ?? ($this->resolvedProviders ??= DefaultCloudProviderRegistryFactory::make(
                new CloudProviderDependencyGuard,
                $this->config,
            ));
    }
}
