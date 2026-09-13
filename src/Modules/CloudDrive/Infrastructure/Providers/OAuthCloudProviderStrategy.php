<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

interface OAuthCloudProviderStrategy extends CloudProviderStrategy
{
    /** @param list<string> $extraScopes */
    public function buildAuthUrl(string $state, array $extraScopes = []): string;

    /** @return array{access_token: string, refresh_token: string|null, expires_at: \Carbon\Carbon} */
    public function exchangeCode(string $code): array;

    /** @return array{access_token: string, refresh_token?: string|null, expires_at: \Carbon\Carbon} */
    public function refreshAccessToken(ConnectedDrive $drive): array;
}
