<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

$requireContains = static function (string $relative, array $needles) use ($root, &$failures): void {
    $path = $root.'/'.$relative;
    $source = is_file($path) ? file_get_contents($path) : false;
    if (! is_string($source)) {
        $failures[] = 'Missing '.$relative;
        return;
    }
    foreach ($needles as $needle) {
        if (! str_contains($source, $needle)) {
            $failures[] = $relative.' must contain '.$needle;
        }
    }
};

$requireContains('src/Modules/Processing/Infrastructure/Queue/Jobs/ProcessMedia.php', [
    'public bool $afterCommit = true',
    'public readonly int $mediaId',
]);
$requireContains('src/Modules/Processing/Infrastructure/Application/MediaProcessingDispatcher.php', [
    'processing_dispatch_attempts',
    'processing_dispatched_at',
    'processing_available_at',
    'dispatchLeaseExpired',
    'MediaProcessingStatus::PENDING',
]);
$requireContains('src/Console/ProcessPendingMediaCommand.php', [
    'MediaProcessingStatus::PROCESSING',
    'processing_started_at',
    'processing_dispatched_at',
    'processing_available_at',
]);
$requireContains('src/Modules/Storage/Infrastructure/StorageOrphanService.php', [
    'next_attempt_at',
    'abandoned_at',
    'shouldAbandon',
]);
$requireContains('database/migrations/2026_06_06_000002_create_media_table.php', [
    'media_processing_recovery_idx',
]);
$requireContains('database/migrations/2026_06_06_000011_create_storage_orphans_table.php', [
    'storage_orphans_due_idx',
]);

$pipeline = file_get_contents($root.'/src/Modules/Processing/Infrastructure/Application/MediaProcessingService.php') ?: '';
$stages = ['ContentInspectionStage', 'MalwareScanStage', 'PostProcessingStage'];
$position = -1;
foreach ($stages as $stage) {
    $next = strpos($pipeline, 'new '.$stage, $position + 1);
    if ($next === false) {
        $failures[] = 'Media processing pipeline is missing '.$stage;
        continue;
    }
    if ($next <= $position) {
        $failures[] = 'Media processing pipeline stage order is invalid.';
        break;
    }
    $position = $next;
}

require_once $root.'/src/Modules/Processing/Domain/Policy/ProcessingRetryPolicy.php';
require_once $root.'/src/Modules/Storage/Domain/Policy/OrphanCleanupRetryPolicy.php';

$now = new DateTimeImmutable('2026-09-13T12:00:00+00:00');
$processing = new Tetranyble\Storage\Modules\Processing\Domain\Policy\ProcessingRetryPolicy([10, 60, 300], 300);
if ($processing->retryAt($now, 2)->getTimestamp() !== $now->getTimestamp() + 60) {
    $failures[] = 'Processing retry backoff policy is not deterministic.';
}
if ($processing->dispatchLeaseExpired($now, $now->modify('+299 seconds'))) {
    $failures[] = 'Processing dispatch lease expires too early.';
}
if (! $processing->dispatchLeaseExpired($now, $now->modify('+300 seconds'))) {
    $failures[] = 'Processing dispatch lease does not expire on schedule.';
}

$orphans = new Tetranyble\Storage\Modules\Storage\Domain\Policy\OrphanCleanupRetryPolicy(3, [60, 300]);
if ($orphans->shouldAbandon(2) || ! $orphans->shouldAbandon(3)) {
    $failures[] = 'Orphan cleanup abandonment policy is invalid.';
}

if ($failures !== []) {
    fwrite(STDERR, "Async resilience verification failed:\n - ".implode("\n - ", $failures)."\n");
    exit(1);
}

fwrite(STDOUT, "Async resilience verification passed: durable media intent, stale-worker recovery, pipeline ordering, and bounded orphan retries are enforced.\n");
