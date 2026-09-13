<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$version = trim((string) ($argv[1] ?? ''));

if ($version === '') {
    fwrite(STDERR, "Usage: php scripts/validate-release-version.php <version-without-v-prefix>\n");
    exit(2);
}

$semver = '/^(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-((?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*)(?:\.(?:0|[1-9]\d*|\d*[A-Za-z-][0-9A-Za-z-]*))*))?(?:\+([0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*))?$/';
if (preg_match($semver, $version) !== 1) {
    fwrite(STDERR, "Invalid Semantic Version [{$version}]. Pass a version such as 3.0.0 or 3.0.0-beta.1.\n");
    exit(1);
}

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
if (! is_array($composer) || ($composer['name'] ?? null) !== 'tetranyble/storage') {
    fwrite(STDERR, "composer.json must identify tetranyble/storage before a release can be created.\n");
    exit(1);
}

$changelog = (string) file_get_contents($root.'/CHANGELOG.md');
$quoted = preg_quote($version, '/');
if (preg_match('/^## \['.$quoted.'\] - \d{4}-\d{2}-\d{2}$/m', $changelog) !== 1) {
    fwrite(STDERR, "CHANGELOG.md must contain a release heading exactly like: ## [{$version}] - YYYY-MM-DD\n");
    exit(1);
}

if (preg_match('/^## \[Unreleased\]$/m', $changelog) !== 1) {
    fwrite(STDERR, "CHANGELOG.md must retain an [Unreleased] section for subsequent work.\n");
    exit(1);
}

fwrite(STDOUT, "Release version {$version} is valid and documented.\n");
