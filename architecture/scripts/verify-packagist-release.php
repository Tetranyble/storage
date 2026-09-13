<?php

declare(strict_types=1);

$version = trim((string) ($argv[1] ?? ''));
$expectedReference = strtolower(trim((string) ($argv[2] ?? '')));

if ($version === '') {
    fwrite(STDERR, "Usage: php scripts/verify-packagist-release.php <version> [git-reference]\n");
    exit(2);
}

$attempts = max(1, (int) (getenv('PACKAGIST_VERIFY_ATTEMPTS') ?: 30));
$interval = max(1, (int) (getenv('PACKAGIST_VERIFY_INTERVAL') ?: 10));
$url = (string) (getenv('PACKAGIST_METADATA_URL') ?: 'https://repo.packagist.org/p2/tetranyble/storage.json');
$wanted = ltrim($version, 'vV');

$context = stream_context_create([
    'http' => [
        'timeout' => 15,
        'header' => "User-Agent: tetranyble-storage-release-verifier\r\nAccept: application/json\r\n",
    ],
]);

for ($attempt = 1; $attempt <= $attempts; $attempt++) {
    $requestUrl = preg_match('#^https?://#i', $url) === 1
        ? $url.(str_contains($url, '?') ? '&' : '?').'t='.time()
        : $url;
    $raw = @file_get_contents($requestUrl, false, $context);
    $payload = is_string($raw) ? json_decode($raw, true) : null;
    $releases = is_array($payload) ? ($payload['packages']['tetranyble/storage'] ?? []) : [];

    foreach (is_array($releases) ? $releases : [] as $release) {
        $found = ltrim((string) ($release['version'] ?? ''), 'vV');
        if ($found !== $wanted) {
            continue;
        }

        $source = $release['source'] ?? [];
        $reference = strtolower((string) ($source['reference'] ?? ''));
        if ($expectedReference !== '' && $reference !== $expectedReference) {
            fwrite(STDERR, "Packagist found {$version}, but source reference {$reference} does not match tested commit {$expectedReference}.\n");
            exit(1);
        }

        fwrite(STDOUT, "Packagist indexed tetranyble/storage {$version}".($reference !== '' ? " at {$reference}" : '').".\n");
        exit(0);
    }

    fwrite(STDOUT, "Packagist has not exposed {$version} yet (attempt {$attempt}/{$attempts}).\n");
    if ($attempt < $attempts) {
        sleep($interval);
    }
}

fwrite(STDERR, "Packagist did not expose tetranyble/storage {$version} within the verification window. Check the package's GitHub auto-update hook.\n");
exit(1);
