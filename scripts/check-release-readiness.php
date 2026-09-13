<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];
$pass = [];

$fail = static function (string $message) use (&$errors): void { $errors[] = $message; };
$ok = static function (string $message) use (&$pass): void { $pass[] = $message; };
$read = static function (string $relative) use ($root, $fail): string {
    $path = $root.'/'.$relative;
    $contents = is_file($path) ? file_get_contents($path) : false;
    if (! is_string($contents)) {
        $fail("Missing required release file [{$relative}].");
        return '';
    }
    return $contents;
};

foreach ([
    'README.md', 'LICENSE', 'SECURITY.md', 'CHANGELOG.md', 'CONTRIBUTING.md',
    'docs/OPERATIONS.md', 'docs/PUBLIC_API_BASELINE.md', 'docs/RELEASE_CHECKLIST.md',
    'docs/PERFORMANCE_AND_INDEXES.md', 'docs/STEP_12_RELEASE_HARDENING.md', 'docs/CI_CD.md', '.env.example', '.gitattributes',
    '.github/workflows/ci.yml', '.github/workflows/release.yml', '.github/dependabot.yml',
    'scripts/validate-release-version.php', 'scripts/verify-packagist-release.php',
] as $file) {
    if (! is_file($root.'/'.$file)) {
        $fail("Missing required release artifact [{$file}].");
    }
}

$composerRaw = $read('composer.json');
$composer = $composerRaw !== '' ? json_decode($composerRaw, true) : null;
if (! is_array($composer)) {
    $fail('composer.json is not valid JSON.');
} else {
    foreach ([
        'name' => 'tetranyble/storage',
        'type' => 'library',
        'license' => 'MIT',
    ] as $key => $expected) {
        if (($composer[$key] ?? null) !== $expected) {
            $fail("composer.json [{$key}] must be [{$expected}].");
        }
    }
    $require = $composer['require'] ?? [];
    if (($require['php'] ?? null) !== '^8.2') {
        $fail('Stable runtime must retain explicit PHP ^8.2 support.');
    }
    foreach (['illuminate/support', 'illuminate/database', 'illuminate/auth'] as $package) {
        if (($require[$package] ?? null) !== '^12.0|^13.0') {
            $fail("Supported Laravel range drifted for [{$package}].");
        }
    }
    $scripts = $composer['scripts'] ?? [];
    $architecture = $scripts['architecture'] ?? [];
    if (! is_array($architecture) || ! in_array('@architecture:release', $architecture, true)) {
        $fail('The architecture gate must include @architecture:release.');
    }
    if (($scripts['architecture:release'] ?? null) !== '@php scripts/check-release-readiness.php') {
        $fail('composer architecture:release must execute the release-readiness checker.');
    }
    if (($scripts['benchmark:queries'] ?? null) !== '@php scripts/run-query-benchmark.php') {
        $fail('composer benchmark:queries contract is missing.');
    }
    $ok('Composer package/runtime metadata is release-pinned.');
}

$config = $read('config/tetranyble-storage.php');
foreach ([
    "'enabled' => env('STORAGE_ROUTES_ENABLED', false)" => 'HTTP routes disabled by default',
    "'enabled' => env('STORAGE_DIRECT_UPLOADS_ENABLED', false)" => 'direct uploads opt-in',
    "'enabled' => env('STORAGE_RETENTION_ENABLED', false)" => 'destructive retention opt-in',
    "'enabled' => env('STORAGE_VIRUS_SCANNING_ENABLED', false)" => 'virus scanner opt-in',
    "'block_private_networks' => true" => 'remote SSRF private-network blocking',
    "'quarantine_until_clean' => env('STORAGE_QUARANTINE_UNTIL_CLEAN', true)" => 'quarantine-until-clean default',
    "'allow_on_scan_failure' => env('STORAGE_ALLOW_ON_SCAN_FAILURE', false)" => 'scan failures fail closed',
] as $needle => $label) {
    if (! str_contains($config, $needle)) {
        $fail("Release-safe configuration drift: {$label}.");
    }
}
$ok('Fail-closed production defaults are present.');

$routeSource = $read('routes/storage.php');
preg_match_all('/Route::(?:get|post|delete|put|patch|match)\s*\(/', $routeSource, $routeMatches);
if (count($routeMatches[0]) !== 46) {
    $fail('Documented HTTP route surface changed from 46 endpoints; review PUBLIC_API_BASELINE.md deliberately.');
} else {
    $ok('46-endpoint HTTP compatibility surface is intact.');
}

$migrationFiles = glob($root.'/database/migrations/*.php') ?: [];
$additive = array_values(array_filter($migrationFiles, static fn (string $path): bool => preg_match('/_add_|_alter_|_update_/', basename($path)) === 1));
if ($additive !== []) {
    $fail('Pre-release additive migrations remain: '.implode(', ', array_map('basename', $additive)).'. Flatten the first stable schema.');
}
$migrationSource = '';
foreach ($migrationFiles as $migration) {
    $migrationSource .= "\n".(file_get_contents($migration) ?: '');
}
foreach ([
    'folders_workspace_deleted_cursor_idx',
    'media_workspace_deleted_cursor_idx',
    'media_workspace_temporary_retention_idx',
    'media_processing_recovery_idx',
    'collaborator_grants_workspace_user_resource_created_idx',
    'resource_stars_workspace_user_resource_created_idx',
    'upload_sessions_workspace_status_updated_idx',
    'upload_sessions_workspace_status_expiry_idx',
    'direct_upload_sessions_workspace_status_updated_idx',
    'connected_drives_workspace_status_expiry_idx',
    'storage_orphans_due_idx',
] as $index) {
    if (! str_contains($migrationSource, $index)) {
        $fail("Release-critical database index [{$index}] is missing.");
    }
}
$ok('Fresh schema is flattened and contains release-critical indexes.');

$workflow = $read('.github/workflows/ci.yml');
foreach ([
    'composer validate --strict', 'composer audit', 'query-performance:', 'composer benchmark:queries', 'name: Required CI',
    'postgres:17', 'mysql:8.4', 'MinIO Community', "php: '8.2'", "php: '8.5'",
] as $needle) {
    if (! str_contains($workflow, $needle)) {
        $fail("CI release coverage is missing [{$needle}].");
    }
}
$ok('CI covers metadata/audit, runtime compatibility, real databases, query budgets and S3 contract testing.');

foreach ([
    'workflow_call:' => 'CI must be reusable by the release workflow',
    'branches:' => 'CI must declare protected branch triggers',
    'composer run production:gate' => 'CI must execute the complete production gate',
] as $needle => $label) {
    if (! str_contains($workflow, $needle)) {
        $fail("CI/CD contract drift: {$label}.");
    }
}

$releaseWorkflow = $read('.github/workflows/release.yml');
foreach ([
    'workflow_dispatch:' => 'release must be explicitly dispatched',
    'uses: ./.github/workflows/ci.yml' => 'release must reuse complete CI before publication',
    'needs: ci' => 'publication must depend on successful CI',
    'environment: release' => 'publication must target the protected release environment',
    'concurrency:' => 'release workflow must serialize publication attempts',
    'git tag -a' => 'publication must create an annotated tag only after CI',
    'gh release create' => 'publication must create a GitHub Release',
    'verify-packagist-release.php' => 'publication must verify Packagist indexing',
] as $needle => $label) {
    if (! str_contains($releaseWorkflow, $needle)) {
        $fail("Release workflow drift: {$label}.");
    }
}
$ok('Release workflow gates public tags behind full CI and verifies Packagist synchronization.');
$archivePosition = strpos($releaseWorkflow, 'Build deterministic source archives from the exact tested commit');
$verifyArchivePosition = strpos($releaseWorkflow, 'Verify packaged Composer metadata');
$tagPosition = strpos($releaseWorkflow, 'Create and push the immutable annotated release tag');
if ($archivePosition === false || $verifyArchivePosition === false || $tagPosition === false || ! ($archivePosition < $verifyArchivePosition && $verifyArchivePosition < $tagPosition)) {
    $fail('Release ordering drift: build and validate artifacts before pushing the immutable public tag.');
}


$env = $read('.env.example');
foreach ([
    'STORAGE_ROUTES_ENABLED=false', 'STORAGE_ALLOW_UNAUTHENTICATED_PROTECTED_ROUTES=false',
    'STORAGE_DIRECT_UPLOADS_ENABLED=false', 'STORAGE_VIRUS_SCANNING_ENABLED=false',
    'STORAGE_QUARANTINE_REQUIRE_PRIVATE=true', 'STORAGE_ALLOW_ON_SCAN_FAILURE=false',
    'STORAGE_RETENTION_ENABLED=false', 'STORAGE_PROCESSING_TIMEOUT=60', 'STORAGE_PROCESSING_STALE_AFTER=15',
] as $needle) {
    if (! str_contains($env, $needle)) {
        $fail(".env.example is missing release-critical setting [{$needle}].");
    }
}
$ok('Deployment example preserves safe defaults.');

$forbiddenFiles = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (! $file->isFile()) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (str_starts_with($relative, 'vendor/') || str_starts_with($relative, '.git/')) {
        continue;
    }
    $base = $file->getBasename();
    if ($base === '.env' || preg_match('/\.(?:pem|key|p12|pfx|sqlite|log|zip)$/i', $base)) {
        $forbiddenFiles[] = $relative;
    }
}
if ($forbiddenFiles !== []) {
    $fail('Release tree contains forbidden runtime/secret/generated artifacts: '.implode(', ', $forbiddenFiles));
} else {
    $ok('Release tree contains no nested archives, local databases, logs or secret-key artifacts.');
}

foreach (['src', 'routes', 'config'] as $directory) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $source = file_get_contents($file->getPathname()) ?: '';
        foreach (['dd(', 'var_dump(', 'print_r('] as $needle) {
            if (str_contains($source, $needle)) {
                $fail('Debug function ['.$needle.'] remains in '.str_replace($root.'/', '', $file->getPathname()).'.');
            }
        }
    }
}
$ok('Production PHP surfaces contain no debug terminators/dumps.');

if ($errors !== []) {
    fwrite(STDERR, "Release-readiness gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Release-readiness gate passed (".count($pass)." checks).\n");
foreach ($pass as $message) {
    fwrite(STDOUT, " - {$message}\n");
}
