<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Carbon\Carbon;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Contracts\CloudAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Adapters\DropboxAdapter;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

final readonly class DropboxProvider implements OAuthCloudProviderStrategy
{
    private const AUTH_URL = 'https://www.dropbox.com/oauth2/authorize';
    private const TOKEN_URL = 'https://api.dropboxapi.com/oauth2/token';

    public function __construct(
        private string $clientId = '',
        private string $clientSecret = '',
        private string $redirectUri = '',
    ) {}

    public function provider(): CloudProvider
    {
        return CloudProvider::DROPBOX;
    }

    public function packageRequirements(): array
    {
        return ['spatie/dropbox-api' => \Spatie\Dropbox\Client::class];
    }

    public function adapter(ConnectedDrive $drive): CloudAdapter
    {
        return new DropboxAdapter($drive->access_token ?? '');
    }

    public function prepareCredentials(array $credentials): void {}

    public function buildAuthUrl(string $state, array $extraScopes = []): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'token_access_type' => 'offline',
            'state' => $state,
        ];
        if ($extraScopes !== []) {
            $params['scope'] = implode(' ', array_unique($extraScopes));
        }

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    public function exchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $this->assertOk($response, 'Dropbox token exchange');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 14400)),
        ];
    }

    public function refreshAccessToken(ConnectedDrive $drive): array
    {
        if (! $drive->refresh_token) {
            throw new RuntimeException('Dropbox refresh token is missing. Reconnect the drive with offline access.');
        }
        $response = Http::asForm()->post(self::TOKEN_URL, [
            'grant_type' => 'refresh_token',
            'refresh_token' => $drive->refresh_token,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);
        $this->assertOk($response, 'Dropbox token refresh');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'expires_at' => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 14400)),
        ];
    }

    private function assertOk(Response $response, string $context): void
    {
        if ($response->failed()) {
            throw new RuntimeException("OAuth error ({$context}): HTTP {$response->status()} — {$response->body()}");
        }
    }
}
