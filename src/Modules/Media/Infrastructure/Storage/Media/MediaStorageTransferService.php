<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaDerivativeService;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageLifecycleService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Support\StorageConfig;
use Throwable;

/**
 * Transfers authoritative media objects between storage drivers.
 *
 * Derivatives are intentionally not copied as opaque sidecars. They are
 * rebuildable first-class assets and are regenerated on the destination after
 * a copy/move, keeping transfer logic independent from derivative variants.
 */
class MediaStorageTransferService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageLifecycleService $lifecycle,
        private readonly StorageOrphanService $orphans,
        private readonly StorageTransferAuthorizer $authorization,
        private readonly ActivityLogger $activityLogger,
        private readonly MediaDerivativeService $derivatives,
        private readonly MediaProcessingDispatcher $processing,
    ) {}

    public function copy(
        Model $workspace,
        Media $media,
        ?Disk $destinationDisk = null,
        ?string $destinationPath = null,
        ?Model $actor = null,
    ): Media {
        $this->assertWorkspaceMedia($workspace, $media);
        $destinationDisk ??= StorageConfig::defaultDisk();
        $this->authorization->authorizeCopy($workspace, $media, $destinationDisk, $actor);

        [$sourceDisk, $sourcePath] = $this->storedLocation($media);
        $destinationPath = $this->destinationPath($sourcePath, $destinationPath, $sourceDisk === $destinationDisk);
        $size = (int) ($media->size ?? $this->files->size($sourcePath, $sourceDisk));
        $this->assertDestinationAvailable($destinationDisk, $destinationPath);

        /** @var Media $copy */
        $copy = $this->lifecycle->storeAndCommit(
            workspace: $workspace,
            disk: $destinationDisk,
            size: $size,
            store: function () use ($sourcePath, $destinationPath, $sourceDisk, $destinationDisk): string {
                if (! $this->files->copy($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
                    throw new RuntimeException('Unable to copy media to the destination storage driver.');
                }

                return $destinationPath;
            },
            commit: function () use ($workspace, $media, $destinationDisk, $destinationPath, $size): Media {
                return DB::transaction(function () use ($workspace, $media, $destinationDisk, $destinationPath, $size): Media {
                    /** @var Media $copy */
                    $copy = $media->replicate([
                        'uuid',
                        'current',
                        'version_group_uuid',
                        'version_number',
                        'previous_version_id',
                        'direct_upload_session_uuid',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ]);
                    $copy->forceFill(array_merge([
                        'uuid' => (string) Str::uuid(),
                        'workspace_id' => $workspace->getKey(),
                        'disk' => $destinationDisk,
                        'path' => $destinationPath,
                        'size' => $size,
                        'current' => false,
                        'version_group_uuid' => (string) Str::uuid(),
                        'version_number' => 1,
                        'previous_version_id' => null,
                        'direct_upload_session_uuid' => null,
                    ], $this->freshProcessingState()))->save();

                    return $copy;
                });
            },
            rollbackReason: 'storage_copy_rollback',
            expectedPath: $destinationPath,
        );

        $this->processing->dispatch($copy);

        $this->activityLogger->log(
            $copy,
            'storage.media.driver.copied',
            'Media copied to another storage driver.',
            $actor,
            ['source_media_id' => $media->getKey(), 'destination_disk' => $destinationDisk->value],
            workspaceId: (int) $workspace->getKey(),
        );

        return $copy->refresh();
    }

    public function move(
        Model $workspace,
        Media $media,
        ?Disk $destinationDisk = null,
        ?string $destinationPath = null,
        ?Model $actor = null,
    ): Media {
        $this->assertWorkspaceMedia($workspace, $media);
        $destinationDisk ??= StorageConfig::defaultDisk();
        $this->authorization->authorizeMove($workspace, $media, $destinationDisk, $actor);

        [$sourceDisk, $sourcePath] = $this->storedLocation($media);
        $destinationPath = $this->destinationPath($sourcePath, $destinationPath, false);

        if ($sourceDisk === $destinationDisk && $sourcePath === $destinationPath) {
            return $media;
        }

        $this->assertDestinationAvailable($destinationDisk, $destinationPath, $sourceDisk, $sourcePath);
        $attempted = false;

        try {
            $attempted = true;
            if (! $this->files->copy($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
                throw new RuntimeException('Unable to copy media to the destination storage driver.');
            }

            DB::transaction(function () use ($media, $destinationDisk, $destinationPath): void {
                $media->forceFill(array_merge([
                    'disk' => $destinationDisk,
                    'path' => $destinationPath,
                ], $this->freshProcessingState()))->save();
            });
        } catch (Throwable $exception) {
            if ($attempted) {
                $this->orphans->deleteOrTrack(
                    $destinationDisk,
                    $destinationPath,
                    (int) $workspace->getKey(),
                    $media->size ? (int) $media->size : null,
                    'storage_move_rollback',
                );
            }
            $this->refreshQuietly($media);
            throw $exception;
        }

        $this->orphans->deleteOrTrack(
            $sourceDisk,
            $sourcePath,
            (int) $workspace->getKey(),
            $media->size ? (int) $media->size : null,
            'storage_move_cleanup',
        );

        // Derivatives can live on a different driver, but a source-driver move
        // normally means the source is being retired. Rebuild them cleanly.
        $this->derivatives->purge($media);
        $this->processing->dispatch($media);

        $this->activityLogger->log(
            $media,
            'storage.media.driver.moved',
            'Media moved to another storage driver.',
            $actor,
            ['source_disk' => $sourceDisk->value, 'destination_disk' => $destinationDisk->value],
            changes: [
                'before' => ['disk' => $sourceDisk->value, 'path' => $sourcePath],
                'after' => ['disk' => $destinationDisk->value, 'path' => $destinationPath],
            ],
            workspaceId: (int) $workspace->getKey(),
        );

        return $media->refresh();
    }

    private function assertWorkspaceMedia(Model $workspace, Media $media): void
    {
        if ((string) $media->workspace_id !== (string) $workspace->getKey()) {
            throw new ResourceNotFoundException;
        }
    }

    /** @return array{0: Disk, 1: string} */
    private function storedLocation(Media $media): array
    {
        $path = trim((string) $media->path, '/');
        if ($path === '' || str_starts_with($path, '//') || filter_var($path, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('External or empty media paths cannot be transferred between storage drivers.');
        }

        return [$media->disk instanceof Disk ? $media->disk : StorageConfig::defaultDisk(), $path];
    }

    private function destinationPath(string $sourcePath, ?string $requestedPath, bool $copyingOnSameDisk): string
    {
        if ($requestedPath !== null) {
            $path = trim(str_replace('\\', '/', $requestedPath), '/');
            if ($path === '' || str_contains('/'.$path.'/', '/../')) {
                throw new RuntimeException('The destination path is invalid.');
            }

            return $path;
        }

        if (! $copyingOnSameDisk) {
            return $sourcePath;
        }

        $directory = trim(dirname($sourcePath), '/.');
        $extension = pathinfo($sourcePath, PATHINFO_EXTENSION);
        $filename = pathinfo($sourcePath, PATHINFO_FILENAME).'-copy-'.Str::lower(Str::random(8));
        $filename .= $extension !== '' ? '.'.$extension : '';

        return ($directory !== '' ? $directory.'/' : '').$filename;
    }

    private function assertDestinationAvailable(
        Disk $destinationDisk,
        string $destinationPath,
        ?Disk $sourceDisk = null,
        ?string $sourcePath = null,
    ): void {
        if ($sourceDisk === $destinationDisk && $sourcePath === $destinationPath) {
            return;
        }

        if ($this->files->exists($destinationPath, $destinationDisk)) {
            throw new RuntimeException('The destination storage path already exists.');
        }
    }

    /** @return array<string,mixed> */
    private function freshProcessingState(): array
    {
        return [
            'processing_status' => MediaProcessingStatus::PENDING,
            'processing_attempts' => 0,
            'processing_dispatch_attempts' => 0,
            'processing_started_at' => null,
            'processing_dispatched_at' => null,
            'processing_available_at' => null,
            'processing_completed_at' => null,
            'processing_error' => null,
            'virus_scan_status' => VirusScanStatus::PENDING,
            'detected_mime_type' => null,
            'scan_started_at' => null,
            'scan_completed_at' => null,
            'scan_engine' => null,
            'scan_signature' => null,
            'quarantined_at' => null,
            'quarantine_reason' => null,
        ];
    }

    private function refreshQuietly(Media $media): void
    {
        try {
            $media->refresh();
        } catch (Throwable) {
            // Preserve the original transfer exception.
        }
    }
}
