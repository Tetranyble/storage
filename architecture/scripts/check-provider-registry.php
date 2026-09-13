<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$servicePath = $root.'/src/Modules/CloudDrive/Infrastructure/ConnectedDriveService.php';
$service = (string) file_get_contents($servicePath);

foreach ([
    'Infrastructure\\Adapters\\',
    'buildGoogleAdapter',
    'buildOneDriveAdapter',
    'buildDropboxAdapter',
    'buildS3Adapter',
    'buildAzureBlobAdapter',
    'buildGcsAdapter',
    'buildCloudinaryAdapter',
    'buildLocalAdapter',
    'match($drive->provider)',
    'supportsOAuth()',
] as $forbidden) {
    if (str_contains($service, $forbidden)) {
        $failures[] = "ConnectedDriveService contains provider-construction detail: {$forbidden}.";
    }
}

if (! str_contains($service, 'connectCredentials(')) {
    $failures[] = 'ConnectedDriveService is missing the provider-neutral connectCredentials() entry point.';
}
if (! str_contains($service, 'providerRegistry()->adapterFor($drive)')) {
    $failures[] = 'ConnectedDriveService does not delegate adapter resolution to CloudProviderRegistry.';
}


$oauthPath = $root.'/src/Modules/CloudDrive/Infrastructure/OAuthService.php';
$oauth = (string) file_get_contents($oauthPath);
foreach (['match($provider)', 'match($drive->provider)', 'CloudProvider::GOOGLE_DRIVE =>', 'CloudProvider::ONEDRIVE =>', 'CloudProvider::DROPBOX =>'] as $forbidden) {
    if (str_contains($oauth, $forbidden)) {
        $failures[] = "OAuthService contains provider-specific dispatch: {$forbidden}.";
    }
}
if (! str_contains($oauth, 'oauthStrategy(')) {
    $failures[] = 'OAuthService does not delegate OAuth behavior to registered provider strategies.';
}

$providerDir = $root.'/src/Modules/CloudDrive/Infrastructure/Providers';
$oauthProviders = ['GoogleDriveProvider.php', 'OneDriveProvider.php', 'DropboxProvider.php'];
$providers = [
    'GoogleDriveProvider.php',
    'OneDriveProvider.php',
    'DropboxProvider.php',
    'S3Provider.php',
    'AzureBlobProvider.php',
    'GcsProvider.php',
    'CloudinaryProvider.php',
    'LocalProvider.php',
];
foreach ($providers as $file) {
    $path = $providerDir.'/'.$file;
    if (! is_file($path)) {
        $failures[] = "Missing cloud provider strategy: {$file}.";
        continue;
    }

    $source = (string) file_get_contents($path);
    if (in_array($file, $oauthProviders, true)) {
        if (! str_contains($source, 'implements OAuthCloudProviderStrategy')) {
            $failures[] = "{$file} must own its OAuth behavior through OAuthCloudProviderStrategy.";
        }
    } elseif (! str_contains($source, 'implements CloudProviderStrategy')) {
        $failures[] = "{$file} must implement CloudProviderStrategy.";
    }
    foreach (['function packageRequirements()', 'function adapter(', 'function prepareCredentials('] as $required) {
        if (! str_contains($source, $required)) {
            $failures[] = "{$file} is missing required strategy behavior: {$required}.";
        }
    }
}

$registryPath = $providerDir.'/CloudProviderRegistry.php';
$registry = is_file($registryPath) ? (string) file_get_contents($registryPath) : '';
foreach (['function register(', 'function adapterFor(', 'function prepareCredentials(', 'function oauthStrategy(', 'function isOAuth(', 'function registeredProviders('] as $required) {
    if (! str_contains($registry, $required)) {
        $failures[] = "CloudProviderRegistry is missing {$required}.";
    }
}

$factoryPath = $providerDir.'/DefaultCloudProviderRegistryFactory.php';
$factory = is_file($factoryPath) ? (string) file_get_contents($factoryPath) : '';
foreach ($providers as $file) {
    $class = basename($file, '.php');
    if (! str_contains($factory, "new {$class}(") && ! str_contains($factory, "new {$class}()")) {
        $failures[] = "Default provider composition is missing {$class}.";
    }
}

$composition = (string) file_get_contents($root.'/src/StorageServiceProvider.php')
    .(string) file_get_contents($root.'/src/Infrastructure/Laravel/StorageBindings.php');
if (! str_contains($composition, 'singleton(CloudProviderRegistry::class')) {
    $failures[] = 'Laravel composition root does not expose CloudProviderRegistry as an extension point.';
}
if (! str_contains($composition, '$app->make(CloudProviderRegistry::class)')) {
    $failures[] = 'ConnectedDriveService composition does not receive CloudProviderRegistry.';
}

if ($failures !== []) {
    fwrite(STDERR, "Cloud provider registry check failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo sprintf(
    "Cloud provider registry check passed: %d provider strategies are registry-owned and central orchestration contains no provider adapter factory.\n",
    count($providers),
);
