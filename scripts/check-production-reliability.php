<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

/** @param list<string> $needles */
function requireMarkers(string $root, string $relative, array $needles): void
{
    global $errors;
    $path = $root.'/'.$relative;
    $source = is_file($path) ? (string) file_get_contents($path) : '';
    if ($source === '') {
        $errors[] = "Missing reliability contract file: {$relative}";

        return;
    }

    foreach ($needles as $needle) {
        if (! str_contains($source, $needle)) {
            $errors[] = "{$relative} is missing reliability contract marker: {$needle}";
        }
    }
}

requireMarkers($root, 'tests/Feature/DatabaseConcurrencyTest.php', [
    'test_atomic_quota_reservation_holds_across_database_processes',
    'test_single_active_upload_identifier_is_enforced_across_database_processes',
    'test_final_share_download_slot_is_atomic_across_database_processes',
    'test_duplicate_direct_finalization_never_creates_two_media_rows_or_double_charges_quota',
    'test_concurrent_first_drive_connections_both_succeed_with_exactly_one_default',
    'pcntl_fork',
]);
requireMarkers($root, 'tests/Unit/StorageLifecycleConsistencyTest.php', [
    'test_failed_media_persistence_removes_new_object_releases_quota_and_rolls_back_folders',
    'test_physical_delete_failure_does_not_resurrect_media_and_is_recorded_for_retry',
    'test_rename_database_failure_keeps_original_object_and_cleans_compensating_copy',
]);
requireMarkers($root, 'tests/Feature/Processing/ProcessingRecoveryCommandTest.php', [
    'test_stale_processing_worker_lease_is_recovered',
    'test_recent_processing_worker_lease_is_not_duplicated',
]);
requireMarkers($root, 'tests/Unit/DirectUploads/DirectUploadServiceTest.php', [
    'test_finalization_is_idempotent_by_direct_upload_session_uuid',
    'test_multipart_signing_enforces_part_range_and_incomplete_finalization_is_retryable',
]);
requireMarkers($root, 'tests/Integration/S3ProviderContractTest.php', [
    'test_filesystem_contract_round_trips_against_s3_compatible_storage',
    'test_direct_upload_gateway_signs_and_inspects_the_same_rooted_object',
    'test_multipart_upload_can_complete_and_be_inspected_end_to_end',
]);
requireMarkers($root, 'tests/Feature/Infrastructure/EloquentRelationshipResolutionTest.php', [
    'test_modularized_model_relationships_resolve_the_intended_related_classes',
]);
requireMarkers($root, 'tests/Unit/CloudDrive/ConnectedDriveServiceTest.php', [
    'test_provider_browse_outage_does_not_mutate_drive_or_workspace_state',
]);
requireMarkers($root, 'tests/Feature/DownloadControllerTest.php', [
    'other workspace',
]);

$workflow = (string) @file_get_contents($root.'/.github/workflows/ci.yml');
foreach ([
    "php: '8.2'",
    "php: '8.5'",
    "laravel: '12'",
    "laravel: '13'",
    'database: PostgreSQL',
    'database: MySQL',
    'pdo_pgsql',
    'pdo_mysql',
    'pcntl',
    'S3 provider contract / MinIO Community',
    'composer run production:gate',
] as $marker) {
    if (! str_contains($workflow, $marker)) {
        $errors[] = "CI reliability matrix is missing: {$marker}";
    }
}

// Model relationship class references are easy to miss after namespace/module moves:
// PHP lint accepts an unresolved Foo::class until the relationship is invoked.
foreach (glob($root.'/src/Modules/*/Infrastructure/Persistence/Eloquent/Models/*.php') ?: [] as $path) {
    $source = (string) file_get_contents($path);
    if (! preg_match('/namespace\s+([^;]+);/', $source, $namespaceMatch)) {
        continue;
    }

    $imports = [];
    foreach (preg_split('/\R/', $source) ?: [] as $line) {
        if (preg_match('/^use\s+([^;{]+?)(?:\s+as\s+(\w+))?;$/', trim($line), $match)) {
            $fqcn = trim($match[1]);
            $alias = $match[2] ?? substr($fqcn, strrpos($fqcn, '\\') + 1);
            $imports[$alias] = $fqcn;
        }
    }

    preg_match_all('/(?<![\\\\\w])([A-Z][A-Za-z0-9_]*)::class/', $source, $references);
    foreach (array_unique($references[1] ?? []) as $class) {
        if (in_array($class, ['self', 'static', 'parent'], true) || isset($imports[$class])) {
            continue;
        }

        $sameNamespace = $namespaceMatch[1].'\\'.$class;
        $relativeClass = str_replace('Tetranyble\\Storage\\', '', $sameNamespace);
        $sameNamespacePath = $root.'/src/'.str_replace('\\', '/', $relativeClass).'.php';
        if (! is_file($sameNamespacePath)) {
            $relative = str_replace($root.'/', '', $path);
            $errors[] = "{$relative} references unresolved {$class}::class; import the modularized model explicitly.";
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "Production reliability gate failed:\n - ".implode("\n - ", $errors)."\n");
    exit(1);
}

echo "Production reliability gate passed: concurrency, recovery, compensation, provider outage, S3 multipart, relationship and CI matrix contracts are present.\n";
