<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Carbon\Carbon;
use Google\Client;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\GoogleDriveAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final readonly class GoogleDriveProvider implements OAuthCloudProviderStrategy
{
    private const AUTH_URL = 'https://accounts.google.com/o/oauth2/v2/auth';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const SCOPES = [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/drive.file',
    ];

    public function __construct(
        private string $clientId,
        private string $clientSecret,
        private string $redirectUri = '',
    ) {}

    public function provider(): CloudProvider
    {
        return CloudProvider::GOOGLE_DRIVE;
    }

    public function packageRequirements(): array
    {
        return ['google/apiclient' => Client::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        return new GoogleDriveAdapter(
            accessToken: $drive->access_token ?? '',
            refreshToken: $drive->refresh_token,
            clientId: $this->clientId,
            clientSecret: $this->clientSecret,
        );
    }

    public function prepareCredentials(array $credentials): void {}

    public function buildAuthUrl(string $state, array $extraScopes = []): string
    {
        return self::AUTH_URL.'?'.http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => implode(' ', array_unique(array_merge(self::SCOPES, $extraScopes))),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ]);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);
        $this->assertOk($response, 'Google token exchange');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    public function refreshAccessToken(ConnectedDrive $drive): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $drive->refresh_token,
            'grant_type' => 'refresh_token',
        ]);
        $this->assertOk($response, 'Google token refresh');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
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
