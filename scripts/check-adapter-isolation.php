<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

require_once $root.'/src/Modules/Storage/Application/DTO/IncomingFile.php';

use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;

$file = new IncomingFile('/tmp/storage-adapter-check', 'check.bin', 0, 'application/octet-stream');
if ($file->originalName !== 'check.bin' || $file->size !== 0) {
    $failures[] = 'IncomingFile application DTO failed its dependency-free construction smoke test.';
}

$provider = (string) file_get_contents($root.'/src/StorageServiceProvider.php')
    .(string) file_get_contents($root.'/src/Infrastructure/Laravel/StorageBindings.php');
$bindings = [
    'MediaUploader::class, EloquentMediaUploader::class',
    'ResumableUploadManager::class, EloquentResumableUploadManager::class',
    'DirectUploadManager::class, EloquentDirectUploadManager::class',
    'ResourceAccessControl::class, EloquentResourceAccessControl::class',
    'UploadLimits::class, ConfiguredUploadLimits::class',
    'StorageEventPublisher::class, LaravelStorageEventPublisher::class',
    'ResourceState::class, EloquentResourceState::class',
    'ResourceIdentity::class, EloquentResourceIdentity::class',
];
foreach ($bindings as $binding) {
    if (! str_contains($provider, $binding)) {
        $failures[] = "Composition root is missing adapter binding: {$binding}.";
    }
}

$required = [
    'src/Http/Adapters/LaravelIncomingFile.php',
    'src/Http/Contracts/WorkspaceContext.php',
    'src/Http/Mail/LaravelMediaMailService.php',
    'src/Infrastructure/Laravel/LaravelStorageEventPublisher.php',
    'src/Modules/Access/Infrastructure/Application/Adapters/EloquentResourceAccessControl.php',
    'src/Modules/DirectUpload/Infrastructure/Application/Adapters/EloquentDirectUploadManager.php',
    'src/Modules/Storage/Infrastructure/Application/Adapters/EloquentMediaUploader.php',
    'src/Modules/Upload/Infrastructure/Application/Adapters/EloquentResumableUploadManager.php',
    'src/Modules/Upload/Infrastructure/Laravel/ConfiguredUploadLimits.php',
    'src/Modules/Shared/Infrastructure/Persistence/Eloquent/EloquentResourceState.php',
    'src/Modules/Remote/Infrastructure/Persistence/Eloquent/EloquentResourceIdentity.php',
];
foreach ($required as $relative) {
    if (! is_file($root.'/'.$relative)) {
        $failures[] = "Missing Step 6 adapter: {$relative}.";
    }
}

foreach ([
    'src/Modules/Workspace/Application/Contracts/Workspace.php',
    'src/Modules/Workspace/Application/Contracts/WorkspaceSubject.php',
] as $legacy) {
    if (is_file($root.'/'.$legacy)) {
        $failures[] = "Laravel/host integration contract still lives in Application: {$legacy}.";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Adapter isolation check failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

echo sprintf("Adapter isolation check passed: %d required adapters/boundaries verified and framework-neutral IncomingFile smoke-tested.\n", count($required));
