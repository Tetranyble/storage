<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Tetranyble\Storage\Modules\DirectUpload\Domain\Enums\DirectUploadStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadSessionStatus;
use Tetranyble\Storage\Modules\DirectUpload\Infrastructure\Persistence\Eloquent\Models\DirectUploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Upload\Infrastructure\Persistence\Eloquent\Models\UploadSession;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;

/** Read/execute retention rules without hiding destructive work behind a scheduler. */
final class StorageRetentionService
{
    public function __construct(
        private readonly MediaDeletionService $deletion,
        private readonly StorageOrphanService $orphans,
    ) {}

    public function run(?int $workspaceId = null, bool $apply = false, ?int $limit = null): array
    {
        $limit = max(1, $limit ?? (int) config('tetranyble-storage.retention.batch_size', 250));
        $now = CarbonImmutable::now();
        $trashCutoff = $now->subDays(max(0, (int) config('tetranyble-storage.retention.trash_days', 30)));
        $temporaryCutoff = $now->subHours(max(0, (int) config('tetranyble-storage.retention.temporary_grace_hours', 0)));
        $uploadCutoff = $now->subDays(max(0, (int) config('tetranyble-storage.retention.terminal_upload_session_days', 7)));
        $directCutoff = $now->subDays(max(0, (int) config('tetranyble-storage.retention.terminal_direct_upload_days', 7)));

        $mediaQuery = Media::query()->withTrashed()
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            ->where(function (Builder $query) use ($trashCutoff, $temporaryCutoff): void {
                $query->where(function (Builder $trash) use ($trashCutoff): void {
                    $trash->whereNotNull('deleted_at')->where('deleted_at', '<=', $trashCutoff);
                })->orWhere(function (Builder $temporary) use ($temporaryCutoff): void {
                    $temporary->where('is_temporary', true)
                        ->whereNotNull('temporary_expires_at')
                        ->where('temporary_expires_at', '<=', $temporaryCutoff);
                });
            })
            ->orderBy('id')
            ->limit($limit);

        $media = $mediaQuery->get();
        $mediaIds = $media->pluck('id')->map(fn ($id) => (int) $id)->all();

        $terminalUploadStatuses = array_map(
            static fn (UploadSessionStatus $status): string => $status->value,
            [UploadSessionStatus::FINALIZED, UploadSessionStatus::CONFLICTED, UploadSessionStatus::CANCELLED, UploadSessionStatus::EXPIRED],
        );
        $uploadSessions = UploadSession::query()
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            ->whereIn('status', $terminalUploadStatuses)
            ->where('updated_at', '<=', $uploadCutoff)
            ->with('chunks')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $terminalDirectStatuses = array_map(
            static fn (DirectUploadStatus $status): string => $status->value,
            [DirectUploadStatus::FINALIZED, DirectUploadStatus::CANCELLED, DirectUploadStatus::EXPIRED, DirectUploadStatus::FAILED],
        );
        $directSessions = DirectUploadSession::query()
            ->when($workspaceId !== null, fn (Builder $query) => $query->where('workspace_id', $workspaceId))
            ->whereIn('status', $terminalDirectStatuses)
            ->where('updated_at', '<=', $directCutoff)
            ->where('cleanup_pending', false)
            ->where('reserved_bytes', 0)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $result = [
            'dry_run' => ! $apply,
            'workspace_id' => $workspaceId,
            'media' => ['eligible' => count($mediaIds), 'deleted' => 0, 'ids' => $mediaIds],
            'upload_sessions' => ['eligible' => $uploadSessions->count(), 'deleted' => 0],
            'direct_upload_sessions' => ['eligible' => $directSessions->count(), 'deleted' => 0],
        ];

        if (! $apply) {
            return $result;
        }

        foreach ($media as $item) {
            $this->deletion->delete($item);
            $result['media']['deleted']++;
        }

        foreach ($uploadSessions as $session) {
            $disk = is_string($session->disk) ? Disk::tryFrom($session->disk) : null;
            if ($disk instanceof Disk) {
                foreach ($session->chunks as $chunk) {
                    if (is_string($chunk->getAttribute('path')) && $chunk->getAttribute('path') !== '') {
                        $this->orphans->deleteOrTrack(
                            $disk,
                            $chunk->path,
                            $session->workspace_id ? (int) $session->workspace_id : null,
                            (int) $chunk->getAttribute('size'),
                            'retention_upload_chunk',
                        );
                    }
                }
            }
            $session->delete();
            $result['upload_sessions']['deleted']++;
        }

        foreach ($directSessions as $session) {
            $session->delete();
            $result['direct_upload_sessions']['deleted']++;
        }

        return $result;
    }
}
