<?php

namespace Tetranyble\Storage\Domain\CloudDrive;

use Carbon\Carbon;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Models\ConnectedDrive;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OAuthService
{
    private const GOOGLE_AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const GOOGLE_TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const GOOGLE_SCOPES    = [
        'https://www.googleapis.com/auth/drive',
        'https://www.googleapis.com/auth/drive.file',
    ];

    private const MS_AUTH_URL  = 'https://login.microsoftonline.com/%s/oauth2/v2.0/authorize';
    private const MS_TOKEN_URL = 'https://login.microsoftonline.com/%s/oauth2/v2.0/token';
    private const MS_SCOPES    = [
        'https://graph.microsoft.com/Files.ReadWrite.All',
        'offline_access',
    ];

    public function __construct(private array $config) {}

    /**
     * Build the OAuth redirect URL for the given provider.
     * $state should be a CSRF token you store in the session.
     */
    public function buildAuthUrl(CloudProvider $provider, string $state, array $extraScopes = []): string
    {
        return match($provider) {
            CloudProvider::GOOGLE_DRIVE => $this->googleAuthUrl($state, $extraScopes),
            CloudProvider::ONEDRIVE     => $this->oneDriveAuthUrl($state, $extraScopes),
            CloudProvider::S3           => throw new RuntimeException('S3 does not use OAuth.'),
        };
    }

    /**
     * Exchange an authorization code for tokens.
     *
     * @return array{access_token: string, refresh_token: string|null, expires_at: \Carbon\Carbon}
     */
    public function exchangeCode(CloudProvider $provider, string $code): array
    {
        return match($provider) {
            CloudProvider::GOOGLE_DRIVE => $this->googleExchangeCode($code),
            CloudProvider::ONEDRIVE     => $this->oneDriveExchangeCode($code),
            CloudProvider::S3           => throw new RuntimeException('S3 does not use OAuth.'),
        };
    }

    /**
     * Refresh an expired OAuth access token and update the ConnectedDrive record.
     */
    public function refreshAccessToken(ConnectedDrive $drive): ConnectedDrive
    {
        $adapter = match($drive->provider) {
            CloudProvider::GOOGLE_DRIVE => $this->refreshGoogle($drive),
            CloudProvider::ONEDRIVE     => $this->refreshOneDrive($drive),
            CloudProvider::S3           => [],
        };

        if (! empty($adapter)) {
            $drive->forceFill([
                'access_token'     => $adapter['access_token'],
                'token_expires_at' => $adapter['expires_at'],
            ]);

            if (isset($adapter['refresh_token'])) {
                $drive->refresh_token = $adapter['refresh_token'];
            }

            $drive->save();
        }

        return $drive->refresh();
    }

    // ---------------------------------------------------------------
    // Google
    // ---------------------------------------------------------------

    private function googleAuthUrl(string $state, array $extraScopes): string
    {
        $scopes = array_unique(array_merge(self::GOOGLE_SCOPES, $extraScopes));

        return self::GOOGLE_AUTH_URL.'?'.http_build_query([
            'client_id'       => $this->cfg('google_drive.client_id'),
            'redirect_uri'    => $this->cfg('google_drive.redirect_uri'),
            'response_type'   => 'code',
            'scope'           => implode(' ', $scopes),
            'state'           => $state,
            'access_type'     => 'offline',
            'prompt'          => 'consent',
        ]);
    }

    private function googleExchangeCode(string $code): array
    {
        $response = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'code'          => $code,
            'client_id'     => $this->cfg('google_drive.client_id'),
            'client_secret' => $this->cfg('google_drive.client_secret'),
            'redirect_uri'  => $this->cfg('google_drive.redirect_uri'),
            'grant_type'    => 'authorization_code',
        ]);

        $this->assertOk($response, 'Google token exchange');
        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at'    => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    private function refreshGoogle(ConnectedDrive $drive): array
    {
        $response = Http::asForm()->post(self::GOOGLE_TOKEN_URL, [
            'client_id'     => $this->cfg('google_drive.client_id'),
            'client_secret' => $this->cfg('google_drive.client_secret'),
            'refresh_token' => $drive->refresh_token,
            'grant_type'    => 'refresh_token',
        ]);

        $this->assertOk($response, 'Google token refresh');
        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'expires_at'   => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    // ---------------------------------------------------------------
    // OneDrive / Microsoft
    // ---------------------------------------------------------------

    private function oneDriveAuthUrl(string $state, array $extraScopes): string
    {
        $msTenantId = $this->cfg('onedrive.tenant_id', 'common');
        $scopes     = array_unique(array_merge(self::MS_SCOPES, $extraScopes));

        return sprintf(self::MS_AUTH_URL, $msTenantId).'?'.http_build_query([
            'client_id'     => $this->cfg('onedrive.client_id'),
            'redirect_uri'  => $this->cfg('onedrive.redirect_uri'),
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $state,
            'response_mode' => 'query',
        ]);
    }

    private function oneDriveExchangeCode(string $code): array
    {
        $msTenantId = $this->cfg('onedrive.tenant_id', 'common');
        $url        = sprintf(self::MS_TOKEN_URL, $msTenantId);

        $response = Http::asForm()->post($url, [
            'code'          => $code,
            'client_id'     => $this->cfg('onedrive.client_id'),
            'client_secret' => $this->cfg('onedrive.client_secret'),
            'redirect_uri'  => $this->cfg('onedrive.redirect_uri'),
            'grant_type'    => 'authorization_code',
            'scope'         => implode(' ', self::MS_SCOPES),
        ]);

        $this->assertOk($response, 'OneDrive token exchange');
        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at'    => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    private function refreshOneDrive(ConnectedDrive $drive): array
    {
        $msTenantId = $this->cfg('onedrive.tenant_id', 'common');
        $url        = sprintf(self::MS_TOKEN_URL, $msTenantId);

        $response = Http::asForm()->post($url, [
            'client_id'     => $this->cfg('onedrive.client_id'),
            'client_secret' => $this->cfg('onedrive.client_secret'),
            'refresh_token' => $drive->refresh_token,
            'grant_type'    => 'refresh_token',
            'scope'         => implode(' ', self::MS_SCOPES),
        ]);

        $this->assertOk($response, 'OneDrive token refresh');
        $data = $response->json();

        return [
            'access_token'  => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_at'    => Carbon::now()->addSeconds((int) ($data['expires_in'] ?? 3600)),
        ];
    }

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    private function cfg(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default)
            ?? config("tetranyble-storage.cloud_drives.{$key}", $default);
    }

    private function assertOk($response, string $context): void
    {
        if ($response->failed()) {
            throw new RuntimeException("OAuth error ({$context}): HTTP {$response->status()} — {$response->body()}");
        }
    }
}
