<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Support\StorageConfig;

class ReconcileStorageUsageCommand extends Command
{
    protected $signature = 'storage:reconcile-usage {workspace? : Workspace primary key to reconcile}';

    protected $description = 'Recompute workspace storage usage from authoritative media rows, including Trash.';

    public function handle(StorageService $storage): int
    {
        $workspaceId = $this->argument('workspace');
        $workspaceModel = StorageConfig::workspaceModelClass();

        if ($workspaceId !== null && $workspaceId !== '') {
            /** @var Model|null $workspace */
            $workspace = $workspaceModel::query()->find($workspaceId);
            if (! $workspace) {
                $this->error('Workspace not found.');

                return self::FAILURE;
            }

            $storage->recalculateUsage($workspace);
            $this->info('Storage usage reconciled for workspace '.$workspace->getKey().'.');

            return self::SUCCESS;
        }

        $count = 0;
        $workspaceModel::query()
            ->orderBy((new $workspaceModel())->getKeyName())
            ->chunkById(100, function ($workspaces) use ($storage, &$count): void {
                foreach ($workspaces as $workspace) {
                    if (! $workspace instanceof Model) {
                        continue;
                    }
                    $storage->recalculateUsage($workspace);
                    $count++;
                }
            });

        $this->info("Storage usage reconciled for {$count} workspace(s).");

        return self::SUCCESS;
    }
}
