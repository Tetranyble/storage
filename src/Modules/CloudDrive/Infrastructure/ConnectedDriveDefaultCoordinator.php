<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Infrastructure;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\ConnectedDriveStatus;
use Tetranyble\Storage\Modules\CloudDrive\Infrastructure\Persistence\Eloquent\Models\ConnectedDrive;

/**
 * Owns the database concurrency rules for the single default drive slot.
 */
final class ConnectedDriveDefaultCoordinator
{
    public function claimFirstSlot(Model $workspace): bool
    {
        $workspace->newQuery()
            ->whereKey($workspace->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        return ! ConnectedDrive::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->exists();
    }

    public function setDefault(Model $workspace, ConnectedDrive $drive): void
    {
        DB::transaction(function () use ($workspace, $drive): void {
            ConnectedDrive::query()
                ->where('workspace_id', $workspace->getKey())
                ->lockForUpdate()
                ->get();
            ConnectedDrive::query()
                ->where('workspace_id', $workspace->getKey())
                ->update(['is_default' => false, 'default_slot' => null]);

            $drive->forceFill(['is_default' => true, 'default_slot' => 'default'])->save();
        }, 3);
    }

    public function getDefault(Model $workspace): ?ConnectedDrive
    {
        return ConnectedDrive::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('is_default', true)
            ->where('status', ConnectedDriveStatus::CONNECTED->value)
            ->first();
    }

    public function promoteOldest(Model $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            $workspace->newQuery()->whereKey($workspace->getKey())->lockForUpdate()->firstOrFail();
            $next = ConnectedDrive::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('status', ConnectedDriveStatus::CONNECTED->value)
                ->oldest('connected_at')
                ->lockForUpdate()
                ->first();

            if ($next) {
                $next->forceFill(['is_default' => true, 'default_slot' => 'default'])->save();
            }
        }, 3);
    }
}
