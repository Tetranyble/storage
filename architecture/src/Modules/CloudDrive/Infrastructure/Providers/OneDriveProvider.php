<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\OneDriveAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final readonly class OneDriveProvider implements OAuthCloudProviderStrategy
{
    private const AUTH_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize';

    private const TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';

    private const SCOPES = ['https://graph.microsoft.com/Files.ReadWrite.All', 'offline_access'];

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $tenantId = 'common',
        private string $redirectUri = '',
    ) {}

    public function provider(): CloudProvider
    {
        return CloudProvider::ONEDRIVE;
    }

    public function packageRequirements(): array
    {
        return [];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        $credentials = $drive->credentials ?? [];

        return new OneDriveAdapter(
            accessToken: $drive->access_token ?? '',
            refreshToken: $drive->refresh_token,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
            tenantId: $this->tenantId,
            drivePath: $credentials['drive_path'] ?? '/me/drive',
        );
    }

    public function prepareCredentials(array $credentials): void {}

    public function buildAuthUrl(string $state, array $extraScopes = []): string
    {
        return sprintf(self::AUTH_URL, $this->tenantId).'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', array_unique(array_merge(self::SCOPES, $extraScopes))),
            'state' => $state,
            'response_mode' => 'query',
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(sprintf(self::TOKEN_URL, $this->tenantId), [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
            'scope' => implode(' ', self::SCOPES),
        ]);
        $this->assertOk($response, 'OneDrive token exchange');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    public function refreshAccessToken(ConnectedDrive $drive): array
    {
        $response = Http::asForm()->post(sprintf(self::TOKEN_URL, $this->tenantId), [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $drive->refresh_token,
            'grant_type' => 'refresh_token',
            'scope' => implode(' ', self::SCOPES),
        ]);
        $this->assertOk($response, 'OneDrive token refresh');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new RuntimeException("OAuth error ({$context}): HTTP {$response->status()} — {$response->body()}");
        }
    }
}
