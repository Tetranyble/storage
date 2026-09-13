<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$configPath = $root.'/.circleci/config.yml';
$errors = [];

if (! is_file($configPath)) {
    fwrite(STDERR, "CircleCI integration gate FAILED:\n - Missing [.circleci/config.yml].\n");
    exit(1);
}

$config = file_get_contents($configPath);
if (! is_string($config)) {
    fwrite(STDERR, "CircleCI integration gate FAILED:\n - Unable to read [.circleci/config.yml].\n");
    exit(1);
}

$required = [
    'version: 2.1' => 'CircleCI 2.1 configuration',
    'image: cimg/php:<< parameters.php_version >>' => 'parameterized PHP compatibility executor',
    'image: cimg/php:8.5' => 'PHP 8.5 quality lane',
    'image: postgres:17' => 'PostgreSQL 17 integration service',
    'image: mysql:8.4' => 'MySQL 8.4 integration service',
    'quay.io/minio/minio:latest' => 'MinIO S3-compatible service',
    'composer run production:gate' => 'complete production gate',
    'composer audit --locked' => 'Composer security audit',
    'composer benchmark:queries' => 'real-database query/N+1 benchmark',
    's3-provider-contract-minio' => 'S3/MinIO provider contract lane',
    'name: CircleCI Required CI' => 'aggregate CircleCI verification job',
    'compatibility-php82-laravel12' => 'PHP 8.2 / Laravel 12',
    'compatibility-php85-laravel12' => 'PHP 8.5 / Laravel 12',
    'compatibility-php83-laravel13' => 'PHP 8.3 / Laravel 13',
    'compatibility-php85-laravel13' => 'PHP 8.5 / Laravel 13',
    'database-postgresql-laravel12' => 'PostgreSQL / Laravel 12',
    'database-mysql-laravel12' => 'MySQL / Laravel 12',
    'database-postgresql-laravel13' => 'PostgreSQL / Laravel 13',
    'database-mysql-laravel13' => 'MySQL / Laravel 13',
];

foreach ($required as $needle => $label) {
    if (! str_contains($config, $needle)) {
        $errors[] = "Missing {$label} contract [{$needle}].";
    }
}

// CircleCI is intentionally verification-only. Keep one authoritative publisher:
// the protected GitHub Actions release workflow.
$forbidden = [
    'git push ' => 'pushing Git refs/tags',
    'git tag -a' => 'creating release tags',
    'gh release' => 'creating GitHub Releases',
    'verify-packagist-release.php' => 'publishing/verifying Packagist releases',
    'api.github.com/repos/' => 'direct GitHub publication API calls',
    'packagist.org/api/' => 'direct Packagist publication API calls',
];

foreach ($forbidden as $needle => $label) {
    if (str_contains($config, $needle)) {
        $errors[] = "CircleCI must remain verification-only; found {$label} [{$needle}].";
    }
}

$requiredCi = strpos($config, 'name: CircleCI Required CI');
if ($requiredCi === false) {
    $errors[] = 'CircleCI aggregate status job is missing.';
} else {
    $tail = substr($config, $requiredCi);
    foreach ([
        '- quality',
        '- compatibility-php82-laravel12',
        '- compatibility-php85-laravel13',
        '- database-postgresql-laravel12',
        '- database-mysql-laravel13',
        '- query-performance-postgresql',
        '- query-performance-mysql',
        '- s3-provider-contract-minio',
    ] as $dependency) {
        if (! str_contains($tail, $dependency)) {
            $errors[] = "CircleCI Required CI does not depend on [{$dependency}].";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "CircleCI integration gate FAILED:\n");
    foreach ($errors as $error) {
        fwrite(STDERR, " - {$error}\n");
    }
    exit(1);
}

fwrite(STDOUT, "CircleCI integration gate passed: verification mirrors release-critical CI and has no publication authority.\n");
