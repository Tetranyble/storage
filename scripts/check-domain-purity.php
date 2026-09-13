<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$domainDirectories = glob($root.'/src/Modules/*/Domain', GLOB_ONLYDIR) ?: [];
$forbidden = [
    'Illuminate\\' => 'Laravel framework',
    'Symfony\\Component\\HttpFoundation\\' => 'Symfony HTTP',
    '\\Application\\' => 'Application layer',
    '\\Infrastructure\\' => 'Infrastructure layer',
    'Tetranyble\\Storage\\Http\\' => 'HTTP adapter',
    'Tetranyble\\Storage\\Support\\' => 'package support/framework bridge',
];
$helperPattern = '/\b(?:config|collect|app|response|request|abort)\s*\(/';
$failures = [];
$count = 0;

foreach ($domainDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $source = (string) file_get_contents($file->getPathname());
        foreach ($forbidden as $needle => $label) {
            if (str_contains($source, $needle)) {
                $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
                $failures[] = "{$relative} depends on {$label}.";
            }
        }
        if (preg_match($helperPattern, $source) === 1) {
            $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
            $failures[] = "{$relative} calls a Laravel/global helper.";
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Domain purity verification failed:\n - ".implode("\n - ", array_unique($failures))."\n");
    exit(1);
}

echo "Domain purity passed: {$count} Domain PHP files are framework/application/infrastructure independent.\n";
