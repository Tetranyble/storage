<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageOrphanService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;

/**
 * Owns physical path relocation/rename mechanics for media objects.
 * Authorization and workspace resolution stay at the application boundary.
 *
 * Relocations are copy -> DB commit -> source cleanup. Originals remain intact
 * until metadata commits, while destination cleanup is compensating/retriable.
 */
class MediaRelocationService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageOrphanService $orphans,
        private readonly ActivityLogger $activityLogger,
    ) {}

    public function rename(Media $media, string $name, ?Model $actor = null): Media
    {
        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new RuntimeException('File name cannot be empty.');
        }

        $disk = $media->disk instanceof Disk ? $media->disk : Disk::default();
        $oldPath = (string) $media->path;
        $oldThumbnailPath = $this->localThumbnailPath($media);
        $newFilename = $this->buildRenamedFilename($oldPath, $trimmed);
        $newPath = $oldPath;

        if ($this->isStoredPath($oldPath)) {
            $directory = trim(dirname($oldPath), '/');
            $newPath = ($directory === '' || $directory === '.') ? $newFilename : $directory.'/'.$newFilename;
        }

        $newThumbnailPath = $oldThumbnailPath !== null && $newPath !== $oldPath
            ? $this->thumbnailPathForOriginal($newPath, $oldThumbnailPath)
            : $oldThumbnailPath;

        $plans = $this->relocationPlans($media, $disk, $oldPath, $newPath, $oldThumbnailPath, $newThumbnailPath);
        $attempted = [];

        try {
            $attempted = $this->copyPlans($plans, $media);

            DB::transaction(function () use ($media, $newPath, $newThumbnailPath, $newFilename, $trimmed): void {
                $media->forceFill([
                    'path' => $newPath,
                    'thumbnail_path' => $newThumbnailPath,
                    'original_name' => $newFilename,
                    'description' => $trimmed,
                ])->save();
            });
        } catch (\Throwable $exception) {
            $this->cleanupDestinations($attempted, $media, 'relocation_rollback');
            $this->refreshQuietly($media);

            throw $exception;
        }

        $this->cleanupSources($plans, $media, 'relocation_cleanup');

        $this->logActivity(
            media: $media,
            type: 'storage.media.renamed',
            description: 'Media renamed.',
            actor: $actor,
            changes: [
                'before' => ['path' => $oldPath, 'original_name' => basename($oldPath)],
                'after' => ['path' => $media->path, 'original_name' => $media->original_name],
            ],
        );

        return $media->refresh();
    }

    public function move(Media $media, Folder $targetFolder, ?Model $actor = null): Media
    {
        $oldPath = (string) $media->path;
        $oldThumbnailPath = $this->localThumbnailPath($media);
        $disk = $media->disk instanceof Disk ? $media->disk : Disk::default();
        $currentFolder = $media->folder_id ? Folder::query()->find($media->folder_id) : null;
        $newPath = $this->isStoredPath($oldPath)
            ? $this->relocateMediaPathBetweenFolders($media, $currentFolder, $targetFolder)
            : $oldPath;
        $newThumbnailPath = $oldThumbnailPath !== null && $newPath !== $oldPath
            ? $this->thumbnailPathForOriginal($newPath, $oldThumbnailPath)
            : $oldThumbnailPath;

        $plans = $this->relocationPlans($media, $disk, $oldPath, $newPath, $oldThumbnailPath, $newThumbnailPath);
        $attempted = [];

        try {
            $attempted = $this->copyPlans($plans, $media);

            DB::transaction(function () use ($media, $newPath, $newThumbnailPath, $targetFolder): void {
                $media->forceFill([
                    'path' => $newPath,
                    'thumbnail_path' => $newThumbnailPath,
                    'folder_id' => $targetFolder->id,
                    'access_scope' => $targetFolder->access_scope ?? $media->access_scope ?? AccessScope::default(),
                ])->save();
            });
        } catch (\Throwable $exception) {
            $this->cleanupDestinations($attempted, $media, 'relocation_rollback');
            $this->refreshQuietly($media);

            throw $exception;
        }

        $this->cleanupSources($plans, $media, 'relocation_cleanup');

        $this->logActivity(
            media: $media,
            type: 'storage.media.moved',
            description: 'Media moved.',
            actor: $actor,
            meta: ['target_folder_id' => $targetFolder->id],
            changes: [
                'before' => ['path' => $oldPath, 'folder_id' => $currentFolder?->id],
                'after' => ['path' => $media->path, 'folder_id' => $media->folder_id],
            ],
        );

        return $media->refresh();
    }

    /**
     * @return array<int, array{source:string,destination:string,disk:Disk,size:int|null}>
     */
    private function relocationPlans(
        Media $media,
        Disk $disk,
        string $oldPath,
        string $newPath,
        ?string $oldThumbnailPath,
        ?string $newThumbnailPath,
    ): array {
        $plans = [];

        if ($this->shouldRelocate($oldPath, $newPath)) {
            $plans[] = [
                'source' => $oldPath,
                'destination' => $newPath,
                'disk' => $disk,
                'size' => $media->size ? (int) $media->size : null,
            ];
        }

        if ($oldThumbnailPath !== null
            && $newThumbnailPath !== null
            && $oldThumbnailPath !== $newThumbnailPath) {
            $plans[] = [
                'source' => $oldThumbnailPath,
                'destination' => $newThumbnailPath,
                'disk' => $disk,
                'size' => null,
            ];
        }

        return $plans;
    }

    /**
     * @param array<int, array{source:string,destination:string,disk:Disk,size:int|null}> $plans
     * @return array<int, array{source:string,destination:string,disk:Disk,size:int|null}>
     */
    private function copyPlans(array $plans, Media $media): array
    {
        foreach ($plans as $plan) {
            if ($this->files->exists($plan['destination'], $plan['disk'])) {
                throw new RuntimeException('The destination storage path already exists.');
            }
        }

        $attempted = [];
        foreach ($plans as $plan) {
            // Record before copy() so a driver that throws after a partial write
            // still has a deterministic compensation target.
            $attempted[] = $plan;

            if (! $this->files->copy($plan['source'], $plan['destination'], $plan['disk'], $plan['disk'])) {
                throw new RuntimeException('Unable to copy media into its new storage location.');
            }
        }

        return $attempted;
    }

    /** @param array<int, array{source:string,destination:string,disk:Disk,size:int|null}> $plans */
    private function cleanupDestinations(array $plans, Media $media, string $reason): void
    {
        foreach (array_reverse($plans) as $plan) {
            $this->orphans->deleteOrTrack(
                $plan['disk'],
                $plan['destination'],
                $media->workspace_id ? (int) $media->workspace_id : null,
                $plan['size'],
                $reason,
            );
        }
    }

    /** @param array<int, array{source:string,destination:string,disk:Disk,size:int|null}> $plans */
    private function cleanupSources(array $plans, Media $media, string $reason): void
    {
        foreach ($plans as $plan) {
            $this->orphans->deleteOrTrack(
                $plan['disk'],
                $plan['source'],
                $media->workspace_id ? (int) $media->workspace_id : null,
                $plan['size'],
                $reason,
            );
        }
    }

    private function shouldRelocate(string $oldPath, string $newPath): bool
    {
        return $oldPath !== '' && $newPath !== $oldPath && $this->isStoredPath($oldPath);
    }

    private function isStoredPath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '//')
            && filter_var($path, FILTER_VALIDATE_URL) === false;
    }

    private function localThumbnailPath(Media $media): ?string
    {
        $path = $media->thumbnail_path;

        return is_string($path) && $this->isStoredPath($path) ? $path : null;
    }

    private function thumbnailPathForOriginal(string $originalPath, string $sourceThumbnail): string
    {
        $directory = trim(dirname($originalPath), '/.');
        $extension = pathinfo($sourceThumbnail, PATHINFO_EXTENSION) ?: 'jpg';
        $filename = pathinfo($originalPath, PATHINFO_FILENAME).'.'.$extension;

        return ($directory !== '' ? $directory.'/' : '').'.thumbnails/'.$filename;
    }

    private function buildRenamedFilename(string $oldPath, string $newName): string
    {
        $newName = trim($newName);
        $oldExtension = pathinfo(parse_url($oldPath, PHP_URL_PATH) ?: $oldPath, PATHINFO_EXTENSION);
        $providedExtension = pathinfo($newName, PATHINFO_EXTENSION);
        $baseName = pathinfo($newName, PATHINFO_FILENAME);

        $safeBase = Str::slug($baseName);
        if ($safeBase === '') {
            $safeBase = 'file';
        }

        $extension = $providedExtension !== '' ? $providedExtension : $oldExtension;

        return $extension !== '' ? "{$safeBase}.{$extension}" : $safeBase;
    }

    private function relocateMediaPathBetweenFolders(Media $media, ?Folder $currentFolder, Folder $targetFolder): string
    {
        $path = trim((string) $media->path, '/');
        if ($path === '') {
            return $path;
        }

        $oldRelative = $currentFolder ? $this->folderRelativePath($currentFolder) : '';
        $newRelative = $this->folderRelativePath($targetFolder);
        $filename = basename($path);
        $directory = trim(dirname($path), '/');
        $directory = $directory === '.' ? '' : $directory;
        $baseDirectory = $directory;

        if ($oldRelative !== '') {
            $suffix = '/'.$oldRelative;
            if ($directory === $oldRelative) {
                $baseDirectory = '';
            } elseif (str_ends_with($directory, $suffix)) {
                $baseDirectory = trim(substr($directory, 0, -strlen($suffix)), '/');
            }
        }

        return trim(implode('/', array_filter(
            [$baseDirectory, $newRelative, $filename],
            fn ($segment) => $segment !== '',
        )), '/');
    }

    private function folderRelativePath(Folder $folder): string
    {
        if ($folder->is_root || $folder->path === 'root') {
            return '';
        }

        return trim((string) Str::of($folder->path)->after('root/')->trim('/'), '/');
    }

    private function refreshQuietly(Media $media): void
    {
        try {
            $media->refresh();
        } catch (\Throwable) {
            // Preserve the mutation exception.
        }
    }

    private function logActivity(
        Media $media,
        string $type,
        string $description,
        ?Model $actor = null,
        array $meta = [],
        array $changes = [],
    ): void {
        $this->activityLogger->log(
            subject: $media,
            type: $type,
            description: $description,
            actor: $actor,
            meta: $meta,
            changes: $changes,
            workspaceId: $media->workspace_id ? (int) $media->workspace_id : null,
        );
    }
}
