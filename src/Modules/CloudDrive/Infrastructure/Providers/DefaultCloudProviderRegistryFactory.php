<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Providers;

use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\CloudProviderDependencyGuard;

final class DefaultCloudProviderRegistryFactory
{
    /** @param array<string, mixed>|null $config */
    public static function make(CloudProviderDependencyGuard $dependencies, ?array $config = null): CloudProviderRegistry
    {
        $config ??= (array) config('tetranyble-storage.cloud_drives', []);

        return new CloudProviderRegistry($dependencies, [
            new GoogleDriveProvider(
                self::value($config, 'google_drive.client_id'),
                self::value($config, 'google_drive.client_secret'),
                self::value($config, 'google_drive.redirect_uri'),
            ),
            new OneDriveProvider(
                self::value($config, 'onedrive.client_id'),
                self::value($config, 'onedrive.client_secret'),
                self::value($config, 'onedrive.tenant_id', 'common'),
                self::value($config, 'onedrive.redirect_uri'),
            ),
            new DropboxProvider(
                self::value($config, 'dropbox.client_id'),
                self::value($config, 'dropbox.client_secret'),
                self::value($config, 'dropbox.redirect_uri'),
            ),
            new S3Provider(),
            new AzureBlobProvider(),
            new GcsProvider(),
            new CloudinaryProvider(),
            new LocalProvider(),
        ]);
    }

    /** @param array<string, mixed> $config */
    private static function value(array $config, string $path, string $default = ''): string
    {
        $value = $config;
        foreach (explode('.', $path) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return is_scalar($value) ? (string) $value : $default;
    }
}
