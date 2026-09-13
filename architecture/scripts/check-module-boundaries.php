<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$catalogPath = $root.'/architecture/modules.json';
$baselinePath = $root.'/architecture/module-dependency-baseline.json';

$catalog = json_decode((string) file_get_contents($catalogPath), true);
$baseline = json_decode((string) file_get_contents($baselinePath), true);
if (! is_array($catalog) || ! isset($catalog['modules']) || ! is_array($baseline) || ! isset($baseline['allowed_edges'])) {
    fwrite(STDERR, "Module architecture metadata is invalid.\n");
    exit(1);
}

$known = array_keys($catalog['modules']);
sort($known);
$knownSet = array_fill_keys($known, true);
$failures = [];
$edges = [];

$moduleRoot = $root.'/src/Modules';
foreach (glob($moduleRoot.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
    $module = basename($directory);
    if (! isset($knownSet[$module])) {
        $failures[] = "Uncatalogued module: {$module}.";

        continue;
    }

    foreach (glob($directory.'/*', GLOB_ONLYDIR) ?: [] as $layerDir) {
        $layer = basename($layerDir);
        if (! in_array($layer, ['Domain', 'Application', 'Infrastructure'], true)) {
            $failures[] = "{$module} contains unsupported core layer {$layer}.";
        }
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $relative = ltrim(str_replace($root, '', $file->getPathname()), '/');
        $parts = explode('/', $relative);
        $layer = $parts[3] ?? null;
        if (! in_array($layer, ['Domain', 'Application', 'Infrastructure'], true)) {
            $failures[] = "{$relative} is not owned by Domain, Application or Infrastructure.";
        }

        $source = (string) file_get_contents($file->getPathname());
        preg_match_all('/Tetranyble\\\\Storage\\\\Modules\\\\([A-Za-z0-9_]+)\\\\/', $source, $matches);
        foreach (array_unique($matches[1] ?? []) as $dependency) {
            if (! isset($knownSet[$dependency])) {
                $failures[] = "{$relative} references unknown module {$dependency}.";

                continue;
            }
            if ($dependency === $module) {
                continue;
            }
            $edges[$module][$dependency] = true;
        }
    }
}

$missing = array_diff($known, array_map('basename', glob($moduleRoot.'/*', GLOB_ONLYDIR) ?: []));
foreach ($missing as $module) {
    $failures[] = "Catalogued module {$module} has no src/Modules/{$module} directory.";
}

foreach ($edges as $from => $dependencies) {
    $allowed = array_fill_keys($baseline['allowed_edges'][$from] ?? [], true);
    foreach (array_keys($dependencies) as $to) {
        if (! isset($allowed[$to])) {
            $failures[] = "New cross-module dependency {$from} -> {$to} is not in the Step 2 module baseline.";
        }
    }
}

if (isset($edges['Shared']) && $edges['Shared'] !== []) {
    $failures[] = 'Shared kernel must not depend on feature modules: '.implode(', ', array_keys($edges['Shared'])).'.';
}

if ($failures !== []) {
    fwrite(STDERR, "Module boundary verification failed:\n - ".implode("\n - ", array_unique($failures))."\n");
    exit(1);
}

$edgeCount = array_sum(array_map('count', $edges));
$baselineCount = array_sum(array_map('count', $baseline['allowed_edges']));
echo 'Module boundaries passed: '.count($known)." modules, {$edgeCount}/{$baselineCount} transitional cross-module edges remain.\n";
