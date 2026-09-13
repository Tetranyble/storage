<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$applicationFiles = [];
foreach (glob($root.'/src/Modules/*/Application', GLOB_ONLYDIR) ?: [] as $directory) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->isFile() && $file->getExtension() === 'php') {
            $applicationFiles[] = $file->getPathname();
        }
    }
}
sort($applicationFiles);

$forbidden = [
    '\\Infrastructure\\' => 'concrete Infrastructure adapter',
    'Illuminate\\' => 'Laravel framework type',
    'Symfony\\Component\\HttpFoundation\\' => 'Symfony HTTP transport type',
    'Tetranyble\\Storage\\Http\\' => 'HTTP adapter',
    'Tetranyble\\Storage\\Support\\' => 'Laravel-aware package Support helper',
];
$helperPattern = '/\\b(?:config|collect|event|app|response|request|abort)\\s*\\(/';

foreach ($applicationFiles as $file) {
    $source = (string) file_get_contents($file);
    $relative = ltrim(str_replace($root, '', $file), '/');
    foreach ($forbidden as $needle => $label) {
        if (str_contains($source, $needle)) {
            $failures[] = "{$relative} references {$label} ({$needle}).";
        }
    }
    if (preg_match($helperPattern, $source) === 1) {
        $failures[] = "{$relative} calls a Laravel/global transport helper.";
    }
}

$requiredPorts = [
    'src/Modules/Access/Application/Contracts/WorkspaceResourceLocator.php',
    'src/Modules/Media/Application/Contracts/MediaDeletion.php',
    'src/Modules/Media/Application/Contracts/MediaLibrary.php',
    'src/Modules/Media/Application/Contracts/MediaRelocation.php',
    'src/Modules/Media/Application/Contracts/MediaRevisionWriter.php',
    'src/Modules/Processing/Application/Contracts/MediaProcessing.php',
    'src/Modules/Sharing/Application/Contracts/MediaShares.php',
    'src/Modules/Versioning/Application/Contracts/CurrentMediaSelection.php',
    'src/Modules/Versioning/Application/Contracts/MediaVersioning.php',
    'src/Modules/Upload/Application/Contracts/UploadLimits.php',
    'src/Modules/Shared/Application/Contracts/StorageEventPublisher.php',
    'src/Modules/Shared/Application/Contracts/ResourceState.php',
    'src/Modules/Remote/Application/Contracts/ResourceIdentity.php',
    'src/Modules/Workspace/Application/Contracts/WorkspaceReadModel.php',
];
foreach ($requiredPorts as $relative) {
    if (! is_file($root.'/'.$relative)) {
        $failures[] = "Missing application port {$relative}.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Application boundary check failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo sprintf(
    "Application boundary check passed: %d Application PHP files are framework/HTTP/Infrastructure independent; %d required ports are present.\n",
    count($applicationFiles),
    count($requiredPorts),
);
