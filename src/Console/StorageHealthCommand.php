<?php

namespace Tetranyble\Storage\Console;

use Illuminate\Console\Command;
use Tetranyble\Storage\Modules\Health\Infrastructure\Application\StorageHealthService;
use Tetranyble\Storage\Modules\Health\Domain\Enums\HealthStatus;
use Tetranyble\Storage\Support\StorageConfig;

final class StorageHealthCommand extends Command
{
    protected $signature = 'storage:health
        {--workspace= : Restrict diagnostics to one workspace primary key}
        {--json : Emit machine-readable JSON}
        {--strict : Return a failing exit code when warnings are present}';

    protected $description = 'Run safe operational diagnostics for Tetranyble Storage.';

    public function handle(StorageHealthService $health): int
    {
        $workspaceId = $this->workspaceId();
        if ($workspaceId === false) {
            return self::FAILURE;
        }

        $results = $health->check($workspaceId);
        $worst = HealthStatus::OK;
        foreach ($results as $result) {
            if ($result->status->severity() > $worst->severity()) {
                $worst = $result->status;
            }
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode([
                'status' => $worst->value,
                'checks' => array_map(static fn ($result) => $result->toArray(), $results),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->table(
                ['Check', 'Status', 'Summary'],
                array_map(static fn ($result) => [
                    $result->name,
                    strtoupper($result->status->value),
                    $result->summary,
                ], $results),
            );
            $this->line('Overall: '.strtoupper($worst->value));
        }

        if ($worst === HealthStatus::CRITICAL) {
            return self::FAILURE;
        }
        if ((bool) $this->option('strict') && $worst === HealthStatus::WARNING) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function workspaceId(): int|false|null
    {
        $raw = $this->option('workspace');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error('The workspace option must be a positive integer primary key.');
            return false;
        }

        $id = (int) $raw;
        $workspaceClass = StorageConfig::workspaceModelClass();
        if (! $workspaceClass::query()->whereKey($id)->exists()) {
            $this->error('Workspace not found.');
            return false;
        }

        return $id;
    }
}
