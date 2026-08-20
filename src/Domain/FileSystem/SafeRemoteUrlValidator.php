<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Contracts\RemoteUrlValidator;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\RemoteDownloadException;

class SafeRemoteUrlValidator implements RemoteUrlValidator
{
    public function assertSafe(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $allowedSchemes = config('tetranyble-storage.remote.allowed_schemes', ['https', 'http']);

        if (! is_array($parts)
            || ! in_array($scheme, $allowedSchemes, true)
            || $host === ''
            || isset($parts['user'])
            || isset($parts['pass'])) {
            $this->reject($url, 'Remote URL must be an HTTP(S) URL without embedded credentials.');
        }

        $allowedHosts = config('tetranyble-storage.remote.allowed_hosts', []);
        if (is_array($allowedHosts) && $allowedHosts !== [] && ! $this->hostIsAllowed($host, $allowedHosts)) {
            $this->reject($url, 'Remote URL host is not allowed.');
        }

        if (! config('tetranyble-storage.remote.block_private_networks', true)) {
            return;
        }

        if ($host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.local')) {
            $this->reject($url, 'Remote URL resolves to a local host.');
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $this->assertPublicIp($url, $host);

            return;
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            $this->reject($url, 'Remote URL host could not be resolved.');
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($ip)) {
                $this->assertPublicIp($url, $ip);
            }
        }
    }

    private function assertPublicIp(string $url, string $ip): void
    {
        if (filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false) {
            $this->reject($url, 'Remote URL resolves to a private or reserved network.');
        }
    }

    private function hostIsAllowed(string $host, array $allowedHosts): bool
    {
        foreach ($allowedHosts as $allowedHost) {
            $allowedHost = strtolower(ltrim((string) $allowedHost, '.'));
            if ($allowedHost !== '' && ($host === $allowedHost || str_ends_with($host, '.'.$allowedHost))) {
                return true;
            }
        }

        return false;
    }

    private function reject(string $url, string $message): never
    {
        throw new RemoteDownloadException($message, $url);
    }
}
