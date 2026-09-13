<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Tetranyble\Storage\Modules\Storage\Infrastructure\Application\StorageRetentionService;

final class StorageRetentionCommand extends Command
{
    protected $signature = 'storage:retention
        {--workspace= : Restrict retention to one workspace ID}
        {--limit= : Maximum records per retention category}
        {--apply : Execute deletions; without this flag the command is a dry run}';

    protected $description = 'Report or apply configured media/upload retention policies.';

    public function handle(StorageRetentionService $retention): int
    {
        $apply = (bool) $this->option('apply');
        if ($apply && ! (bool) config('tetranyble-storage.retention.enabled', false)) {
            $this->error('Retention execution is disabled. Set STORAGE_RETENTION_ENABLED=true before using --apply.');

            return self::FAILURE;
        }

        $workspace = $this->option('workspace');
        $result = $retention->run(
            workspaceId: is_numeric($workspace) ? (int) $workspace : null,
            apply: $apply,
            limit: is_numeric($this->option('limit')) ? (int) $this->option('limit') : null,
        );

        $this->table(
            ['Category', 'Eligible', $apply ? 'Deleted' : 'Would delete'],
            [
                ['media', $result['media']['eligible'], $apply ? $result['media']['deleted'] : $result['media']['eligible']],
                ['upload sessions', $result['upload_sessions']['eligible'], $apply ? $result['upload_sessions']['deleted'] : $result['upload_sessions']['eligible']],
                ['direct upload sessions', $result['direct_upload_sessions']['eligible'], $apply ? $result['direct_upload_sessions']['deleted'] : $result['direct_upload_sessions']['eligible']],
            ],
        );

        $this->info($apply ? 'Retention applied.' : 'Dry run only; pass --apply to execute.');

        return self::SUCCESS;
    }
}
