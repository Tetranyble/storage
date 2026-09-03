<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\StorageTransferAuthorizer;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageLifecycleService;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Models\Media;

class MediaStorageTransferService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageLifecycleService $lifecycle,
        private readonly StorageOrphanService $orphans,
        private readonly StorageTransferAuthorizer $authorization,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function copy(
        Model $workspace,
        Media $media,
        ?Disk $destinationDisk = null,
        ?string $destinationPath = null,
        ?Model $actor = null,
    ): Media {
        $this->assertWorkspaceMedia($workspace, $media);
        $destinationDisk ??= Disk::default();
        $this->authorization->authorizeCopy($workspace, $media, $destinationDisk, $actor);

        [$sourceDisk, $sourcePath] = $this->storedLocation($media);
        $destinationPath = $this->destinationPath($sourcePath, $destinationPath, $sourceDisk === $destinationDisk);
        $size = (int) ($media->size ?? $this->files->size($sourcePath, $sourceDisk));
        $sourceThumbnail = $this->storedThumbnailPath($media);
        $destinationThumbnail = $sourceThumbnail !== null
            ? $this->thumbnailPathForOriginal($destinationPath, $sourceThumbnail)
            : null;
        $thumbnailAttempted = false;

        $this->assertDestinationAvailable($destinationDisk, $destinationPath);
        if ($destinationThumbnail !== null) {
            $this->assertDestinationAvailable($destinationDisk, $destinationThumbnail);
        }

        try {
            $copy = $this->lifecycle->storeAndCommit(
                workspace: $workspace,
                disk: $destinationDisk,
                size: $size,
                store: function () use (
                    $sourcePath,
                    $destinationPath,
                    $sourceDisk,
                    $destinationDisk,
                    $sourceThumbnail,
                    $destinationThumbnail,
                    &$thumbnailAttempted,
                    $workspace,
                ): string {
                    if (! $this->files->copy($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
                        throw new RuntimeException('Unable to copy media to the destination storage driver.');
                    }

                    if ($sourceThumbnail !== null && $destinationThumbnail !== null) {
                        $thumbnailAttempted = true;
                        try {
                            if (! $this->files->copy($sourceThumbnail, $destinationThumbnail, $sourceDisk, $destinationDisk)) {
                                throw new RuntimeException('Unable to copy media thumbnail to the destination storage driver.');
                            }
                        } catch (Throwable $exception) {
                            $this->orphans->deleteOrTrack(
                                $destinationDisk,
                                $destinationThumbnail,
                                (int) $workspace->getKey(),
                                reason: 'storage_copy_rollback',
                            );
                            throw $exception;
                        }
                    }

                    return $destinationPath;
                },
                commit: function () use (
                    $workspace,
                    $media,
                    $destinationDisk,
                    $destinationPath,
                    $destinationThumbnail,
                    $size,
                ): Media {
                    return DB::transaction(function () use (
                        $workspace,
                        $media,
                        $destinationDisk,
                        $destinationPath,
                        $destinationThumbnail,
                        $size,
                    ): Media {
                        /** @var Media $copy */
                        $copy = $media->replicate([
                            'uuid',
                            'current',
                            'version_group_uuid',
                            'version_number',
                            'previous_version_id',
                            'created_at',
                            'updated_at',
                            'deleted_at',
                        ]);
                        $copy->forceFill([
                            'uuid' => (string) Str::uuid(),
                            'workspace_id' => $workspace->getKey(),
                            'disk' => $destinationDisk,
                            'path' => $destinationPath,
                            'thumbnail_path' => $destinationThumbnail,
                            'size' => $size,
                            'current' => false,
                            'version_group_uuid' => (string) Str::uuid(),
                            'version_number' => 1,
                            'previous_version_id' => null,
                        ])->save();

                        return $copy;
                    });
                },
                rollbackReason: 'storage_copy_rollback',
                expectedPath: $destinationPath,
            );
        } catch (Throwable $exception) {
            // StorageLifecycleService compensates the primary object. Derivatives
            // are separate physical objects and need their own compensation.
            if ($thumbnailAttempted && $destinationThumbnail !== null) {
                $this->orphans->deleteOrTrack(
                    $destinationDisk,
                    $destinationThumbnail,
                    (int) $workspace->getKey(),
                    reason: 'storage_copy_rollback',
                );
            }

            throw $exception;
        }

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
        $destinationDisk ??= Disk::default();
        $this->authorization->authorizeMove($workspace, $media, $destinationDisk, $actor);

        [$sourceDisk, $sourcePath] = $this->storedLocation($media);
        $destinationPath = $this->destinationPath($sourcePath, $destinationPath, false);
        $sourceThumbnail = $this->storedThumbnailPath($media);
        $destinationThumbnail = $sourceThumbnail !== null
            ? $this->thumbnailPathForOriginal($destinationPath, $sourceThumbnail)
            : null;

        if ($sourceDisk === $destinationDisk && $sourcePath === $destinationPath) {
            return $media;
        }

        $this->assertDestinationAvailable($destinationDisk, $destinationPath, $sourceDisk, $sourcePath);
        if ($destinationThumbnail !== null) {
            $this->assertDestinationAvailable($destinationDisk, $destinationThumbnail, $sourceDisk, $sourceThumbnail);
        }

        $attempted = [];

        try {
            $attempted[] = [
                'disk' => $destinationDisk,
                'path' => $destinationPath,
                'size' => $media->size ? (int) $media->size : null,
            ];
            if (! $this->files->copy($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
                throw new RuntimeException('Unable to copy media to the destination storage driver.');
            }

            if ($sourceThumbnail !== null && $destinationThumbnail !== null) {
                $attempted[] = ['disk' => $destinationDisk, 'path' => $destinationThumbnail, 'size' => null];
                if (! $this->files->copy($sourceThumbnail, $destinationThumbnail, $sourceDisk, $destinationDisk)) {
                    throw new RuntimeException('Unable to copy media thumbnail to the destination storage driver.');
                }
            }

            DB::transaction(function () use ($media, $destinationDisk, $destinationPath, $destinationThumbnail): void {
                $media->forceFill([
                    'disk' => $destinationDisk,
                    'path' => $destinationPath,
                    'thumbnail_path' => $destinationThumbnail,
                ])->save();
            });
        } catch (Throwable $exception) {
            foreach (array_reverse($attempted) as $object) {
                $this->orphans->deleteOrTrack(
                    $object['disk'],
                    $object['path'],
                    (int) $workspace->getKey(),
                    $object['size'],
                    'storage_move_rollback',
                );
            }
            $this->refreshQuietly($media);

            throw $exception;
        }

        // Once metadata points at the destination, source cleanup is eventual.
        // A storage outage leaves a durable orphan instead of rolling the DB back.
        $this->orphans->deleteOrTrack(
            $sourceDisk,
            $sourcePath,
            (int) $workspace->getKey(),
            $media->size ? (int) $media->size : null,
            'storage_move_cleanup',
        );
        if ($sourceThumbnail !== null) {
            $this->orphans->deleteOrTrack(
                $sourceDisk,
                $sourceThumbnail,
                (int) $workspace->getKey(),
                reason: 'storage_move_cleanup',
            );
        }

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
        abort_unless((string) $media->workspace_id === (string) $workspace->getKey(), 404);
    }

    /** @return array{0: Disk, 1: string} */
    private function storedLocation(Media $media): array
    {
        $path = trim((string) $media->path, '/');
        if ($path === '' || str_starts_with($path, '//') || filter_var($path, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('External or empty media paths cannot be transferred between storage drivers.');
        }

        return [$media->disk instanceof Disk ? $media->disk : Disk::default(), $path];
    }

    private function storedThumbnailPath(Media $media): ?string
    {
        $path = $media->thumbnail_path;
        if (! is_string($path) || $path === '' || str_starts_with($path, '//') || filter_var($path, FILTER_VALIDATE_URL)) {
            return null;
        }

        return trim($path, '/');
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

    private function thumbnailPathForOriginal(string $originalPath, string $sourceThumbnail): string
    {
        $directory = trim(dirname($originalPath), '/.');
        $extension = pathinfo($sourceThumbnail, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = pathinfo($originalPath, PATHINFO_FILENAME).'.'.$extension;

        return ($directory !== '' ? $directory.'/' : '').'.thumbnails/'.$filename;
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

    private function refreshQuietly(Media $media): void
    {
        try {
            $media->refresh();
        } catch (Throwable) {
            // Preserve the original transfer exception.
        }
    }
}
