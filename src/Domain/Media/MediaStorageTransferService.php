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
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Models\Media;

class MediaStorageTransferService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageService $storage,
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
        $this->storage->assertCanStore($workspace, $size);

        if (! $this->files->copy($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
            throw new RuntimeException('Unable to copy media to the destination storage driver.');
        }

        try {
            $copy = DB::transaction(function () use ($workspace, $media, $destinationDisk, $destinationPath, $size): Media {
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
                    'size' => $size,
                    'current' => false,
                    'version_group_uuid' => (string) Str::uuid(),
                    'version_number' => 1,
                    'previous_version_id' => null,
                ])->save();
                $this->storage->increaseUsage($workspace, $size);

                return $copy;
            });
        } catch (Throwable $exception) {
            $this->files->delete($destinationPath, $destinationDisk);
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

        if ($sourceDisk === $destinationDisk && $sourcePath === $destinationPath) {
            return $media;
        }

        if (! $this->files->move($sourcePath, $destinationPath, $sourceDisk, $destinationDisk)) {
            throw new RuntimeException('Unable to move media to the destination storage driver.');
        }

        try {
            $media->forceFill(['disk' => $destinationDisk, 'path' => $destinationPath])->save();
        } catch (Throwable $exception) {
            $this->files->move($destinationPath, $sourcePath, $destinationDisk, $sourceDisk);
            throw $exception;
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
        if ($path === '' || filter_var($path, FILTER_VALIDATE_URL)) {
            throw new RuntimeException('External or empty media paths cannot be transferred between storage drivers.');
        }

        return [$media->disk instanceof Disk ? $media->disk : Disk::default(), $path];
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
}
