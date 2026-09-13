<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$baselinePath = $root.'/architecture/dependency-baseline.json';

if (! is_file($baselinePath)) {
    fwrite(STDERR, "Dependency baseline is missing: architecture/dependency-baseline.json\n");
    exit(1);
}

/** @var array{rules?: array<string, array{module_layer?:string, directory?:string, needle:string, target:string}>, violations?: array<string, int>} $baseline */
$baseline = json_decode((string) file_get_contents($baselinePath), true);
if (! is_array($baseline) || ! isset($baseline['rules'], $baseline['violations'])) {
    fwrite(STDERR, "Dependency baseline is invalid.\n");
    exit(1);
}

$phpFiles = static function (string $directory) use ($root): array {
    $base = $root.'/'.$directory;
    if (! is_dir($base)) {
        return [];
    }

    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $files[] = $file->getPathname();
        }
    }
    sort($files);

    return $files;
};

$moduleLayerFiles = static function (string $layer) use ($root, $phpFiles): array {
    $files = [];
    foreach (glob($root.'/src/Modules/*/'.$layer, GLOB_ONLYDIR) ?: [] as $directory) {
        $relativeDirectory = ltrim(str_replace($root, '', $directory), '/');
        $files = array_merge($files, $phpFiles($relativeDirectory));
    }
    sort($files);

    return $files;
};

$current = [];
foreach ($baseline['rules'] as $rule => $definition) {
    $files = isset($definition['module_layer'])
        ? $moduleLayerFiles($definition['module_layer'])
        : $phpFiles($definition['directory']);

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $count = substr_count($source, $definition['needle']);
        if ($count === 0) {
            continue;
        }

        $relative = ltrim(str_replace($root, '', $path), '/');
        $current[$rule.'|'.$relative] = $count;
    }
}
ksort($current);

$failures = [];
foreach ($current as $key => $count) {
    $allowed = (int) ($baseline['violations'][$key] ?? 0);
    if ($count > $allowed) {
        $failures[] = sprintf('%s increased from %d to %d occurrence(s).', $key, $allowed, $count);
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Dependency ratchet failed. New architectural debt is forbidden:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

$currentTotal = array_sum($current);
$baselineTotal = array_sum(array_map('intval', $baseline['violations']));
$remainingFiles = count($current);
$baselineFiles = count($baseline['violations']);

echo "Dependency ratchet passed: {$currentTotal}/{$baselineTotal} debt occurrences remain across {$remainingFiles}/{$baselineFiles} tracked rule/file pairs.\n";
