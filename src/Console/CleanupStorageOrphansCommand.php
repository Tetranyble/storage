<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Models\StorageOrphan;

class CleanupStorageOrphansCommand extends Command
{
    protected $signature = 'storage:cleanup-orphans {--limit=100 : Maximum orphan objects to retry}';

    protected $description = 'Retry deletion of physical storage objects recorded as orphans.';

    public function handle(StorageOrphanService $orphans): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $cleaned = 0;
        $failed = 0;

        StorageOrphan::query()
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (StorageOrphan $orphan) use ($orphans, &$cleaned, &$failed): void {
                if ($orphans->cleanup($orphan)) {
                    $cleaned++;
                } else {
                    $failed++;
                }
            });

        $this->info("Storage orphan cleanup complete: {$cleaned} cleaned, {$failed} still pending.");

        return self::SUCCESS;
    }
}
