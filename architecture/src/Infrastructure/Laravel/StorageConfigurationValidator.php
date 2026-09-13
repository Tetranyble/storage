<?php

namespace Tetranyble\Storage\Infrastructure\Laravel;

use Illuminate\Contracts\Config\Repository;
use RuntimeException;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Support\StorageConfig;

final class StorageConfigurationValidator
{
    public function __construct(private readonly Repository $config) {}

    public function validate(): void
    {
        StorageConfig::assertHostModelKeyCompatibility();
        $this->assertProcessingAndTrustAreCompatible();
        $this->assertRouteSecurity();
        $this->assertOperationalLimits();
        $this->assertQueueTiming();
        $this->assertScannerConfiguration();
    }

    private function assertProcessingAndTrustAreCompatible(): void
    {
        if ($this->bool('tetranyble-storage.trust.virus_scanning.enabled')
            && ! $this->bool('tetranyble-storage.processing.enabled', true)) {
            throw new RuntimeException('Media processing must remain enabled when virus scanning is enabled.');
        }
    }

    private function assertRouteSecurity(): void
    {
        if (! $this->bool('tetranyble-storage.routes.enabled')) {
            return;
        }

        $middleware = $this->config->get('tetranyble-storage.routes.middleware', []);
        if (! is_array($middleware) || $middleware === []) {
            throw new RuntimeException('Enabled storage routes require protected middleware.');
        }

        $allowUnauthenticated = $this->bool('tetranyble-storage.routes.allow_unauthenticated_protected_routes');
        $hasAuth = false;
        foreach ($middleware as $value) {
            if (is_string($value) && ($value === 'auth' || str_starts_with($value, 'auth:'))) {
                $hasAuth = true;
                break;
            }
        }

        if (! $allowUnauthenticated && ! $hasAuth) {
            throw new RuntimeException(
                'Protected storage routes must include auth middleware. Set routes.allow_unauthenticated_protected_routes=true only when authorization is enforced by a custom middleware.'
            );
        }

        $public = $this->config->get('tetranyble-storage.routes.public_middleware', []);
        if (! is_array($public)) {
            throw new RuntimeException('Storage public_middleware must be an array.');
        }
    }

    private function assertOperationalLimits(): void
    {
        foreach ([
            'tetranyble-storage.routes.rate_limits.public_per_minute' => 30,
            'tetranyble-storage.routes.rate_limits.authenticated_per_minute' => 240,
            'tetranyble-storage.bulk.max_items' => 100,
            'tetranyble-storage.processing.tries' => 3,
            'tetranyble-storage.processing.timeout_seconds' => 60,
            'tetranyble-storage.processing.dispatch_lease_seconds' => 300,
            'tetranyble-storage.orphan_cleanup.max_attempts' => 10,
            'tetranyble-storage.direct_uploads.url_ttl_seconds' => 900,
            'tetranyble-storage.direct_uploads.session_ttl_minutes' => 60,
        ] as $key => $default) {
            $value = $this->config->get($key, $default);
            if (! is_int($value) || $value < 1) {
                throw new RuntimeException("Storage configuration [{$key}] must be a positive integer.");
            }
        }

        $partSize = $this->config->get('tetranyble-storage.direct_uploads.part_size', 8 * 1024 * 1024);
        if (! is_int($partSize) || $partSize < 5 * 1024 * 1024) {
            throw new RuntimeException('Direct-upload part size must be at least 5 MiB for multipart S3 compatibility.');
        }
    }


    private function assertQueueTiming(): void
    {
        if (! $this->bool('tetranyble-storage.processing.enabled', true)
            || ! $this->bool('tetranyble-storage.processing.auto_dispatch', true)
            || $this->bool('tetranyble-storage.processing.inline')) {
            return;
        }

        $connection = $this->config->get('tetranyble-storage.processing.connection')
            ?: $this->config->get('queue.default');
        if (! is_string($connection) || $connection === '') {
            return;
        }

        $retryAfter = $this->config->get("queue.connections.{$connection}.retry_after");
        if (! is_int($retryAfter)) {
            // SQS and host-managed queue systems may control visibility outside Laravel config.
            return;
        }

        $timeout = (int) $this->config->get('tetranyble-storage.processing.timeout_seconds', 60);
        if ($retryAfter <= $timeout) {
            throw new RuntimeException(
                "Queue connection [{$connection}] retry_after must be greater than the storage processing timeout ({$timeout}s) to prevent overlapping workers."
            );
        }
    }

    private function assertScannerConfiguration(): void
    {
        if (! $this->bool('tetranyble-storage.trust.virus_scanning.enabled')) {
            return;
        }

        $scanner = $this->config->get('tetranyble-storage.trust.virus_scanning.scanner');
        if (! is_string($scanner) || ! is_a($scanner, MediaScanner::class, true)) {
            throw new RuntimeException('The configured media scanner must implement '.MediaScanner::class.'.');
        }
    }

    private function bool(string $key, bool $default = false): bool
    {
        return (bool) $this->config->get($key, $default);
    }
}
