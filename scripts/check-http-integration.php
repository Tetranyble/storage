<?php

declare(strict_types=1);
use Tetranyble\Storage\Http\Responses\SafeDownloadFilename;

$root = dirname(__DIR__);
$failures = [];

require_once $root.'/src/Http/Responses/SafeDownloadFilename.php';
$unsafeName = "../folder/evil\r\nheader.pdf";
$safeName = SafeDownloadFilename::from($unsafeName);
if (str_contains($safeName, "\r") || str_contains($safeName, "\n") || str_contains($safeName, '/') || str_contains($safeName, '\\')) {
    $failures[] = 'SafeDownloadFilename failed to remove path/header control characters.';
}

$routes = (string) file_get_contents($root.'/routes/storage.php');
foreach ([
    'AddStorageSecurityHeaders::class',
    'throttle:tetranyble-storage-public',
    'throttle:tetranyble-storage-authenticated',
    'HandleStorageExceptions::class',
] as $required) {
    if (! str_contains($routes, $required)) {
        $failures[] = "Storage routes are missing HTTP boundary protection: {$required}.";
    }
}

$config = (string) file_get_contents($root.'/config/tetranyble-storage.php');
foreach ([
    "'allow_unauthenticated_protected_routes'",
    "'public_per_minute'",
    "'authenticated_per_minute'",
] as $required) {
    if (! str_contains($config, $required)) {
        $failures[] = "Storage route configuration is missing: {$required}.";
    }
}

$provider = (string) file_get_contents($root.'/src/StorageServiceProvider.php');
foreach (['StorageConfigurationValidator::class', 'StorageBindings::register(', 'StorageRateLimiters::class'] as $required) {
    if (! str_contains($provider, $required)) {
        $failures[] = "StorageServiceProvider is missing composition-root delegation: {$required}.";
    }
}
if (substr_count($provider, '->bind(') > 0 || substr_count($provider, '->singleton(') > 1) {
    $failures[] = 'StorageServiceProvider must remain a thin composition root; feature bindings belong in StorageBindings.';
}

$controllers = glob($root.'/src/Http/Controllers/*.php') ?: [];
foreach ($controllers as $file) {
    $source = (string) file_get_contents($file);
    if (preg_match('/\\babort(?:_if|_unless)?\\s*\\(/', $source) === 1) {
        $failures[] = basename($file).' still terminates through Laravel abort helpers instead of package exceptions.';
    }
    if (str_contains($source, '::query()') || str_contains($source, 'firstOrFail(')) {
        $failures[] = basename($file).' performs persistence queries directly; controllers must delegate route/resource lookup.';
    }
}

$shareController = (string) file_get_contents($root.'/src/Http/Controllers/MediaShareController.php');
if (! str_contains($shareController, "isMethod('post')") || str_contains($shareController, "->input('password')") && ! str_contains($shareController, "isMethod('post')")) {
    $failures[] = 'Public share passwords must not be consumed from GET query strings.';
}

$baseController = (string) file_get_contents($root.'/src/Http/Controllers/StorageController.php');
if (str_contains($baseController, '::query()') || str_contains($baseController, 'firstOrFail(')) {
    $failures[] = 'StorageController still performs persistence queries; route resolution must stay in the HTTP adapter.';
}
if (! str_contains($baseController, 'WorkspaceRouteResolver')) {
    $failures[] = 'StorageController does not delegate route-resource resolution.';
}

$exceptions = (string) file_get_contents($root.'/src/Http/Middleware/HandleStorageExceptions.php');
foreach (['validation_failed', 'authentication_required', 'resource_not_found', 'internal_error', 'report($exception)', 'expectsJson()', 'ApiErrorResponder'] as $required) {
    if (! str_contains($exceptions, $required)) {
        $failures[] = "HTTP exception boundary is missing stable API behavior: {$required}.";
    }
}

$headers = (string) file_get_contents($root.'/src/Http/Middleware/AddStorageSecurityHeaders.php');
foreach (['X-Content-Type-Options', 'Referrer-Policy', 'X-Frame-Options', 'Permissions-Policy'] as $required) {
    if (! str_contains($headers, $required)) {
        $failures[] = "Storage security-header middleware is missing {$required}.";
    }
}

foreach (['DownloadResponder.php', 'MediaStreamResponder.php'] as $file) {
    $source = (string) file_get_contents($root.'/src/Http/Responses/'.$file);
    if (! str_contains($source, 'makeDisposition(') || str_contains($source, 'addslashes(')) {
        $failures[] = "{$file} must build Content-Disposition with Symfony's safe disposition helper.";
    }
}

$validator = (string) file_get_contents($root.'/src/Infrastructure/Laravel/StorageConfigurationValidator.php');
foreach (['Protected storage routes must include auth middleware', 'Direct-upload part size must be at least 5 MiB', 'Media processing must remain enabled'] as $required) {
    if (! str_contains($validator, $required)) {
        $failures[] = "Configuration validator is missing fail-fast rule: {$required}.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "HTTP integration hardening check failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo sprintf(
    "HTTP integration hardening passed: %d controllers checked; stable errors, fail-closed config, rate limits, security headers, route resolution and safe download headers enforced.\n",
    count($controllers),
);
