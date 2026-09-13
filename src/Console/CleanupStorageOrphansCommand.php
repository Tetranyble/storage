<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Persistence\Eloquent\Models\StorageOrphan;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;

class CleanupStorageOrphansCommand extends Command
{
    protected $signature = 'storage:cleanup-orphans
        {--limit=100 : Maximum due orphan objects to retry}
        {--retry-abandoned : Manually retry objects that exhausted automatic cleanup attempts}';

    protected $description = 'Retry due physical-object cleanup intents with bounded backoff.';

    public function handle(StorageOrphanService $orphans): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $retryAbandoned = (bool) $this->option('retry-abandoned');
        $cleaned = 0;
        $failed = 0;

        StorageOrphan::query()
            ->when(! $retryAbandoned, fn (Builder $query) => $query->whereNull('abandoned_at'))
            ->where(function (Builder $query): void {
                $query->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now());
            })
            ->orderByRaw('next_attempt_at IS NOT NULL')
            ->orderBy('next_attempt_at')
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
