<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$baselinePath = $root.'/architecture/complexity-baseline.json';
$defaultMaximum = 350;

/** @var array{maximum_new_class_lines?:int, oversized_files?:array<string,int>} $baseline */
$baseline = json_decode((string) file_get_contents($baselinePath), true);
if (! is_array($baseline) || ! isset($baseline['oversized_files'])) {
    fwrite(STDERR, "Complexity baseline is missing or invalid.\n");
    exit(1);
}
$defaultMaximum = (int) ($baseline['maximum_new_class_lines'] ?? $defaultMaximum);

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/src'));
foreach ($iterator as $file) {
    if (! ($file instanceof SplFileInfo) || ! $file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
    $lineCount = count(file($file->getPathname(), FILE_IGNORE_NEW_LINES));
    $files[$relative] = $lineCount;
}
ksort($files);

$failures = [];
foreach ($files as $path => $lineCount) {
    $baselineLimit = $baseline['oversized_files'][$path] ?? null;
    if ($baselineLimit !== null) {
        if ($lineCount > (int) $baselineLimit) {
            $failures[] = "{$path} grew from {$baselineLimit} to {$lineCount} lines; oversized files may only shrink.";
        }
        continue;
    }

    if ($lineCount > $defaultMaximum) {
        $failures[] = "{$path} is {$lineCount} lines; new/untracked classes must stay at or below {$defaultMaximum}.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Complexity ratchet failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

$remaining = array_filter($files, static fn (int $lines): bool => $lines > $defaultMaximum);
echo 'Complexity ratchet passed: '.count($remaining).' oversized source files remain; none grew. New class budget: '.$defaultMaximum." lines.\n";
