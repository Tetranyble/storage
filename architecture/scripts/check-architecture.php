<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$fail = static function (string $message) use (&$failures): void {
    $failures[] = $message;
};

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

$relative = static fn (string $path): string => ltrim(str_replace($root, '', $path), '/');
$source = static fn (string $path): string => (string) file_get_contents($root.'/'.$path);

// 1. Every package PHP file must parse without loading Composer dependencies.
foreach (['src', 'tests', 'database', 'config', 'routes', 'scripts'] as $directory) {
    foreach ($phpFiles($directory) as $path) {
        $output = [];
        $status = 0;
        exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($path).' 2>&1', $output, $status);
        if ($status !== 0) {
            $fail($relative($path).': '.implode(' ', $output));
        }
    }
}

// 2. Canonical dependency direction inside capability modules.
$moduleFilesByLayer = static function (string $layer) use ($phpFiles): array {
    $files = [];
    foreach (glob(dirname(__DIR__).'/src/Modules/*/'.$layer, GLOB_ONLYDIR) ?: [] as $directory) {
        $relativeDirectory = ltrim(str_replace(dirname(__DIR__), '', $directory), '/');
        $files = array_merge($files, $phpFiles($relativeDirectory));
    }
    sort($files);

    return $files;
};

$forbiddenNamespacesByLayer = [
    'Domain' => ['\\Application\\', '\\Infrastructure\\', '\\Http\\'],
    'Application' => ['\\Http\\'],
    'Infrastructure' => ['\\Http\\'],
];
foreach ($forbiddenNamespacesByLayer as $layer => $fragments) {
    foreach ($moduleFilesByLayer($layer) as $path) {
        $contents = (string) file_get_contents($path);
        foreach ($fragments as $fragment) {
            if (str_contains($contents, 'Tetranyble\\Storage\\Modules\\') && str_contains($contents, $fragment)) {
                if (preg_match('/Tetranyble\\\\Storage\\\\Modules\\\\[^;\s]+'.preg_quote($fragment, '/').'/', $contents) === 1) {
                    $fail($relative($path).' violates '.$layer.' dependency direction via '.$fragment);
                }
            }
        }
        if ($layer !== 'Infrastructure' && str_contains($contents, 'Tetranyble\\Storage\\Http\\')) {
            $fail($relative($path).' depends on the HTTP adapter.');
        }
    }
}

// 3. HTTP semantics stay in Laravel/HTTP adapters.
foreach (array_merge($moduleFilesByLayer('Domain'), $moduleFilesByLayer('Application'), $moduleFilesByLayer('Infrastructure')) as $path) {
    $contents = (string) file_get_contents($path);
    if (preg_match('/\\babort(?:_if|_unless)?\\s*\\(/', $contents) === 1) {
        $fail($relative($path).' contains an HTTP abort helper inside a capability core.');
    }
}

// 4. Every source namespace matches its PSR-4 path, including capability modules.
foreach ($phpFiles('src') as $path) {
    $contents = (string) file_get_contents($path);
    if (preg_match('/^namespace\\s+([^;]+);/m', $contents, $match) !== 1) {
        $fail($relative($path).' has no namespace declaration.');

        continue;
    }
    $underSrc = substr($relative($path), strlen('src/'));
    $directory = dirname($underSrc);
    $expected = 'Tetranyble\\Storage'.($directory === '.' ? '' : '\\'.str_replace('/', '\\', $directory));
    if ($match[1] !== $expected) {
        $fail($relative($path)." namespace {$match[1]} does not match {$expected}.");
    }
}

// 5. First release contains no migration/namespace compatibility scaffolding.
foreach ($phpFiles('src') as $path) {
    $contents = (string) file_get_contents($path);
    if (str_contains($contents, 'class_alias(') || str_contains($contents, 'Compatibility alias retained')) {
        $fail($relative($path).' contains a pre-release compatibility alias.');
    }
}
foreach ([
    'src/Application/Legacy',
    'src/Facades/FileManager.php',
    'src/Concerns/InteractsWithMedia.php',
    'src/Concerns/LegacyDocumentMediaAccessors.php',
] as $legacyPath) {
    if (file_exists($root.'/'.$legacyPath)) {
        $fail($legacyPath.' must not exist in the first release.');
    }
}

// 6. Composer support policy and optional provider split.
$composer = json_decode((string) file_get_contents($root.'/composer.json'), true);
if (! is_array($composer)) {
    $fail('composer.json is not valid JSON.');
} else {
    if (($composer['require']['php'] ?? null) !== '^8.2') {
        $fail('PHP support must remain ^8.2 unless CI is changed with it.');
    }
    if (($composer['require']['illuminate/support'] ?? null) !== '^12.0|^13.0') {
        $fail('Laravel support must remain the verified 12/13 window.');
    }
    if (($composer['require']['illuminate/pagination'] ?? null) !== '^12.0|^13.0') {
        $fail('Cursor APIs require illuminate/pagination as a runtime dependency.');
    }

    $optional = [
        'azure-oss/storage-blob-flysystem',
        'cloudinary/cloudinary_php',
        'google/apiclient',
        'league/flysystem-aws-s3-v3',
        'league/flysystem-google-cloud-storage',
        'spatie/dropbox-api',
    ];
    foreach ($optional as $package) {
        if (isset($composer['require'][$package])) {
            $fail($package.' must remain optional.');
        }
        if (! isset($composer['suggest'][$package])) {
            $fail($package.' must be documented in Composer suggest.');
        }
    }
    foreach (['league/flysystem-azure-blob-storage', 'microsoft/microsoft-graph'] as $removed) {
        if (isset($composer['require'][$removed]) || isset($composer['require-dev'][$removed])) {
            $fail($removed.' must not be reintroduced.');
        }
    }
}

// 7. Fresh-install schema contains the final invariants directly.
$mediaMigration = $source('database/migrations/2026_06_06_000002_create_media_table.php');
foreach ([
    "unsignedBigInteger('size')",
    'media_version_group_number_unique',
    'media_direct_upload_session_unique',
    'folders_workspace_visibility_idx', // checked in folder migration below too
] as $needle) {
    if ($needle === 'folders_workspace_visibility_idx') {
        continue;
    }
    if (! str_contains($mediaMigration, $needle)) {
        $fail('Fresh media schema is missing '.$needle.'.');
    }
}
foreach ([
    'detected_mime_type',
    'processing_status',
    'processing_attempts',
    'scan_engine',
    'scan_signature',
    'quarantined_at',
    'media_workspace_visibility_idx',
    'media_workspace_processing_status_index',
    'media_workspace_scan_status_index',
    'media_workspace_updated_cursor_idx',
    'media_workspace_created_cursor_idx',
] as $needle) {
    if (! str_contains($mediaMigration, $needle)) {
        $fail('Fresh media schema is missing '.$needle.'.');
    }
}
if (str_contains($mediaMigration, "double('size')") || str_contains($mediaMigration, 'thumbnail_path')) {
    $fail('Fresh media schema must use integer bytes and must not contain thumbnail_path.');
}

$folderMigration = $source('database/migrations/2026_06_06_000001_create_folders_table.php');
foreach (['folders_workspace_visibility_idx', 'folders_workspace_name_cursor_idx'] as $index) {
    if (! str_contains($folderMigration, $index)) {
        $fail('Fresh folder schema is missing '.$index.'.');
    }
}

$grantMigration = $source('database/migrations/2026_06_06_000004_create_collaborator_grants_table.php');
if (! str_contains($grantMigration, 'collaborator_grants_workspace_user_resource_idx')) {
    $fail('Fresh collaborator schema must retain ACL visibility index.');
}

$activityMigration = $source('database/migrations/activities/2026_06_06_000005_create_activities_table.php');
foreach (['activities_workspace_subject_visibility_idx', 'activities_workspace_type_cursor_idx'] as $index) {
    if (! str_contains($activityMigration, $index)) {
        $fail('Fresh activity schema is missing '.$index.'.');
    }
}

$uploadMigration = $source('database/migrations/2026_06_06_000007_create_upload_sessions_tables.php');
foreach (['active_identifier_hash', 'upload_sessions_active_identifier_unique'] as $needle) {
    if (! str_contains($uploadMigration, $needle)) {
        $fail('Fresh resumable-upload schema is missing '.$needle.'.');
    }
}

$directMigration = $source('database/migrations/2026_06_06_000012_create_direct_upload_sessions_table.php');
foreach (['reserved_bytes', 'expected_sha256', 'cleanup_pending', 'direct_upload_sessions_expiry_idx'] as $needle) {
    if (! str_contains($directMigration, $needle)) {
        $fail('Fresh direct-upload schema is missing '.$needle.'.');
    }
}

$derivativeMigration = $source('database/migrations/2026_06_06_000013_create_media_derivatives_table.php');
foreach (['media_derivatives', 'media_derivative_variant_unique', 'media_derivative_primary_idx', 'sha256', 'generated_at'] as $needle) {
    if (! str_contains($derivativeMigration, $needle)) {
        $fail('Fresh derivative schema is missing '.$needle.'.');
    }
}

// 8. Large-workspace reads are explicit CQRS handlers with cursor traversal.
$queryService = $source('src/Modules/Workspace/Infrastructure/Queries/WorkspaceFileQueryService.php');
foreach (['WorkspaceReadModel', 'SearchWorkspaceHandler', 'RecentWorkspaceHandler', 'ActivityWorkspaceHandler'] as $needle) {
    if (! str_contains($queryService, $needle)) {
        $fail('WorkspaceFileQueryService compatibility adapter is missing '.$needle.'.');
    }
}
foreach ([
    'SearchWorkspaceHandler.php',
    'RecentWorkspaceHandler.php',
    'ActivityWorkspaceHandler.php',
] as $handler) {
    $handlerSource = $source('src/Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers/'.$handler);
    if (! str_contains($handlerSource, 'cursorPaginate(')) {
        $fail($handler.' must retain cursor pagination.');
    }
    if (str_contains($handlerSource, '->offset(')) {
        $fail($handler.' must not use SQL OFFSET.');
    }
}

// 9. Upload, trust and lifecycle invariants remain below HTTP.
$mediaService = $source('src/Modules/Media/Infrastructure/Storage/MediaService.php');
if (! str_contains($mediaService, 'assertConfiguredUploadSize') || ! str_contains($mediaService, 'MediaStoragePathResolver')) {
    $fail('MediaService must retain package upload ceilings and path delegation.');
}
$resumableService = $source('src/Modules/Upload/Infrastructure/ResumableUploadService.php');
foreach (['activeIdentifierHash', 'candidateChunkFilename', 'lockForUpdate()', 'UploadSessionStatus::ASSEMBLING', 'ResumableUploadOptionsCodec', 'QuarantineStoragePolicy'] as $needle) {
    if (! str_contains($resumableService, $needle)) {
        $fail('ResumableUploadService is missing '.$needle.'.');
    }
}
$processingService = $source('src/Modules/Processing/Infrastructure/Application/MediaProcessingService.php');
foreach (['lockForUpdate()', 'processing_attempts', 'MediaProcessingPipeline', 'UnsafeMediaException'] as $needle) {
    if (! str_contains($processingService, $needle)) {
        $fail('MediaProcessingService is missing '.$needle.'.');
    }
}
$contentInspectionStage = $source('src/Modules/Processing/Infrastructure/Pipeline/ContentInspectionStage.php');
foreach (['MediaContentInspector', 'isCompatibleWith'] as $needle) {
    if (! str_contains($contentInspectionStage, $needle)) {
        $fail('ContentInspectionStage is missing '.$needle.'.');
    }
}
$malwareScanStage = $source('src/Modules/Processing/Infrastructure/Pipeline/MalwareScanStage.php');
foreach (['MediaScanner', 'VirusScanStatus::INFECTED', 'VirusScanStatus::FAILED', 'MalwareDetectedException'] as $needle) {
    if (! str_contains($malwareScanStage, $needle)) {
        $fail('MalwareScanStage is missing '.$needle.'.');
    }
}
$postProcessor = $source('src/Modules/Processing/Infrastructure/ImageProcessing/MediaPostProcessor.php');
foreach (['getimagesizefromstring', 'max_pixels', 'MediaDerivativeService', 'imagewebp', 'imageavif', 'ImageOrientationNormalizer'] as $needle) {
    if (! str_contains($postProcessor, $needle)) {
        $fail('MediaPostProcessor is missing '.$needle.'.');
    }
}
if (str_contains($postProcessor, 'thumbnail_path')) {
    $fail('MediaPostProcessor must use first-class derivatives, not thumbnail_path.');
}

foreach ([
    'src/Modules/Download/Infrastructure/Application/DownloadService.php',
    'src/Modules/Media/Application/MediaMailService.php',
    'src/Http/Controllers/MediaShareController.php',
    'src/Modules/CloudDrive/Infrastructure/ConnectedDriveService.php',
] as $deliveryPath) {
    if (! str_contains($source($deliveryPath), 'MediaDeliveryGuard')) {
        $fail($deliveryPath.' must enforce quarantine before delivery/export.');
    }
}

// 10. Direct uploads remain optional, verified, and quota-aware.
$directService = $source('src/Modules/DirectUpload/Infrastructure/DirectUploadService.php');
foreach (['increaseUsage', 'verifiedChecksum', 'registerDirectUploadObject', 'cleanupExpired', 'abortMultipart', 'stream_checksum_fallback'] as $needle) {
    if (! str_contains($directService, $needle)) {
        $fail('DirectUploadService is missing '.$needle.'.');
    }
}
$s3Gateway = $source('src/Modules/DirectUpload/Infrastructure/S3DirectUploadGateway.php');
foreach (['createPresignedRequest', 'createMultipartUpload', 'completeMultipartUpload', 'headObject', 'ChecksumType'] as $needle) {
    if (! str_contains($s3Gateway, $needle)) {
        $fail('S3 direct-upload gateway is missing '.$needle.'.');
    }
}
$storageService = $source('src/Modules/Storage/Infrastructure/StorageService.php');
if (! str_contains($storageService, 'DirectUploadSession::query()') || ! str_contains($storageService, "where('reserved_bytes', '>', 0)")) {
    $fail('Quota reconciliation must include active direct-upload reservations.');
}

// 11. Phase 6 first-class derivatives, retention and bulk APIs.
foreach ([
    'src/Modules/Processing/Infrastructure/Persistence/Eloquent/Models/MediaDerivative.php',
    'src/Modules/Processing/Infrastructure/ImageProcessing/MediaDerivativeService.php',
    'src/Modules/Storage/Infrastructure/Application/StorageRetentionService.php',
    'src/Modules/Media/Infrastructure/Application/Bulk/BulkMediaService.php',
    'src/Console/StorageRetentionCommand.php',
    'src/Http/Controllers/BulkMediaController.php',
] as $requiredPath) {
    if (! is_file($root.'/'.$requiredPath)) {
        $fail('Phase 6 component is missing '.$requiredPath.'.');
    }
}
$mediaModel = $source('src/Modules/Media/Infrastructure/Persistence/Eloquent/Models/Media.php');
if (! str_contains($mediaModel, 'function derivatives()') || str_contains($mediaModel, 'thumbnail_path')) {
    $fail('Media must expose first-class derivatives and contain no thumbnail_path compatibility state.');
}
$deletion = $source('src/Modules/Media/Infrastructure/Storage/Media/MediaDeletionService.php');
if (! str_contains($deletion, 'derivatives')) {
    $fail('Permanent deletion must remove derivative assets.');
}
$retention = $source('src/Modules/Storage/Infrastructure/Application/StorageRetentionService.php');
if (! str_contains($retention, "config('tetranyble-storage.retention") || ! str_contains($retention, 'bool $apply = false')) {
    $fail('Retention must remain explicit/configured and dry-run by default.');
}
$bulk = $source('src/Modules/Media/Infrastructure/Application/Bulk/BulkMediaService.php');
foreach (['TrashMedia', 'RestoreMedia', 'DeleteMedia', 'MoveMedia', 'bulk.max_items'] as $needle) {
    if (! str_contains($bulk, $needle)) {
        $fail('Bulk media orchestration is missing '.$needle.'.');
    }
}

// 12. CI keeps real DB and MinIO provider contract coverage.
$ci = (string) file_get_contents($root.'/.github/workflows/ci.yml');
foreach (['postgres:17', 'mysql:8.4', 's3-provider-contract:', 'github.com/minio/minio@latest', 'S3ProviderContractTest.php'] as $needle) {
    if (! str_contains($ci, $needle)) {
        $fail('CI provider/database coverage is missing '.$needle.'.');
    }
}

foreach ([
    'tests/Benchmark/LargeWorkspaceQueryBenchmarkTest.php',
    'tests/Support/LargeWorkspaceFixture.php',
    'tests/Support/QueryPlanInspector.php',
    'scripts/run-query-benchmark.php',
    'tests/Integration/S3ProviderContractTest.php',
] as $requiredPath) {
    if (! is_file($root.'/'.$requiredPath)) {
        $fail('Release validation support is missing '.$requiredPath.'.');
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Architecture verification failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

$count = 0;
foreach (['src', 'tests', 'database', 'config', 'routes', 'scripts'] as $directory) {
    $count += count($phpFiles($directory));
}

echo "Architecture verification passed ({$count} PHP files linted).\n";
