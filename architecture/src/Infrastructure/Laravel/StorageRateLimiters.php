<?php

namespace Tetranyble\Storage\Infrastructure\Laravel;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

final class StorageRateLimiters
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly Repository $config,
    ) {}

    public function register(): void
    {
        $this->limiter->for('tetranyble-storage-public', function (Request $request): Limit {
            $limit = max(1, (int) $this->config->get(
                'tetranyble-storage.routes.rate_limits.public_per_minute',
                30,
            ));
            $token = (string) ($request->route('token') ?? 'public');

            return Limit::perMinute($limit)->by($this->ip($request).'|'.hash('sha256', $token));
        });

        $this->limiter->for('tetranyble-storage-authenticated', function (Request $request): Limit {
            $limit = max(1, (int) $this->config->get(
                'tetranyble-storage.routes.rate_limits.authenticated_per_minute',
                240,
            ));
            $actor = $request->user();
            $identifier = is_object($actor) && method_exists($actor, 'getAuthIdentifier')
                ? (string) $actor->getAuthIdentifier()
                : 'guest';

            return Limit::perMinute($limit)->by($identifier.'|'.$this->ip($request));
        });
    }

    private function ip(Request $request): string
    {
        return $request->ip() ?: 'unknown';
    }
}
