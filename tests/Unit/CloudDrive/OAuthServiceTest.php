<?php

namespace Tetranyble\Storage\Tests\Unit\CloudDrive;

use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Enums\CloudProvider;
use Tetranyble\Storage\Tests\PackageTestCase;
use Illuminate\Support\Facades\Http;

class OAuthServiceTest extends PackageTestCase
{
    private OAuthService $oauth;

    protected function setUp(): void
    {
        parent::setUp();

        $this->oauth = new OAuthService([
            'google_drive' => [
                'client_id'     => 'goog-client-id',
                'client_secret' => 'goog-client-secret',
                'redirect_uri'  => 'https://app.test/auth/google/callback',
            ],
            'onedrive' => [
                'client_id'     => 'ms-client-id',
                'client_secret' => 'ms-client-secret',
                'redirect_uri'  => 'https://app.test/auth/onedrive/callback',
                'tenant_id'     => 'common',
            ],
        ]);
    }

    public function test_build_google_auth_url_contains_required_params(): void
    {
        $url = $this->oauth->buildAuthUrl(CloudProvider::GOOGLE_DRIVE, 'csrf-state-token');

        $this->assertStringContainsString('accounts.google.com', $url);
        $this->assertStringContainsString('goog-client-id', $url);
        $this->assertStringContainsString('csrf-state-token', $url);
        $this->assertStringContainsString('offline', $url);
        $this->assertStringContainsString('drive', $url);
    }

    public function test_build_onedrive_auth_url_contains_required_params(): void
    {
        $url = $this->oauth->buildAuthUrl(CloudProvider::ONEDRIVE, 'csrf-state-token');

        $this->assertStringContainsString('login.microsoftonline.com', $url);
        $this->assertStringContainsString('ms-client-id', $url);
        $this->assertStringContainsString('csrf-state-token', $url);
        $this->assertStringContainsString('Files.ReadWrite', rawurldecode($url));
    }

    public function test_build_auth_url_throws_for_s3(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OAuth/i');

        $this->oauth->buildAuthUrl(CloudProvider::S3, 'state');
    }

    public function test_exchange_code_google_returns_token_data(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token'  => 'goog-access-token',
                'refresh_token' => 'goog-refresh-token',
                'expires_in'    => 3600,
                'token_type'    => 'Bearer',
            ], 200),
        ]);

        $data = $this->oauth->exchangeCode(CloudProvider::GOOGLE_DRIVE, 'auth-code-123');

        $this->assertSame('goog-access-token', $data['access_token']);
        $this->assertSame('goog-refresh-token', $data['refresh_token']);
        $this->assertInstanceOf(\Carbon\Carbon::class, $data['expires_at']);
        $this->assertTrue($data['expires_at']->isFuture());
    }

    public function test_exchange_code_onedrive_returns_token_data(): void
    {
        Http::fake([
            'https://login.microsoftonline.com/*/oauth2/v2.0/token' => Http::response([
                'access_token'  => 'ms-access-token',
                'refresh_token' => 'ms-refresh-token',
                'expires_in'    => 3600,
            ], 200),
        ]);

        $data = $this->oauth->exchangeCode(CloudProvider::ONEDRIVE, 'auth-code-456');

        $this->assertSame('ms-access-token', $data['access_token']);
        $this->assertSame('ms-refresh-token', $data['refresh_token']);
    }

    public function test_exchange_code_throws_on_http_error(): void
    {
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OAuth error/');

        $this->oauth->exchangeCode(CloudProvider::GOOGLE_DRIVE, 'bad-code');
    }

    public function test_exchange_code_throws_for_s3(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->oauth->exchangeCode(CloudProvider::S3, 'code');
    }
}
