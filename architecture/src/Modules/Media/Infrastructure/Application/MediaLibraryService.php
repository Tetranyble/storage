<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageOrphanService;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Support\StorageConfig;

class MediaLibraryService
{
    public function __construct(
        protected FileSystemContract $files,
        protected StorageService $storage,
        protected StorageOrphanService $orphans,
        protected MediaDeletionService $deletion,
        protected MediaProcessingDispatcher $processing,
    ) {}

    public function createWorkspaceRoot(Model $workspace): Folder
    {
        $folder = Folder::firstOrCreate([
            'workspace_id' => $workspace->getKey(),
            'path' => 'root',
        ], [
            'parent_id' => null,
            'name' => $workspace->getAttribute('name'),
            'slug' => Str::slug($workspace->getAttribute('name').'-root'),
            'is_root' => true,
        ]);

        $prefix = "workspaces/{$workspace->getKey()}";
        $diskValue = config('filesystems.default', 'local');
        $disk = Disk::tryFrom($diskValue)
            ?? Disk::PRIVATE;
        $this->files->disk($disk)
            ->makeDirectory($prefix);

        return $folder;
    }

    public function createFolder(Model $workspace, string $name, ?Folder $parent = null): Folder
    {
        $slug = Str::slug($name);
        if ($slug === '') {
            $slug = 'folder';
        }

        $basePath = $parent?->path ?? 'root';
        $path = $this->uniqueChildPath($workspace->getKey(), $basePath, $slug);

        return Folder::create([
            'workspace_id' => $workspace->getKey(),
            'parent_id' => $parent?->id,
            'name' => $name,
            'slug' => basename($path),
            'path' => $path,
            'is_root' => false,
        ]);
    }

    public function renameFolder(Folder $folder, string $name): Folder
    {
        $folder = $this->assertMutableFolder($folder);

        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new RuntimeException('Folder name cannot be empty.');
        }

        $slug = Str::slug($trimmed);
        if ($slug === '') {
            $slug = 'folder';
        }

        $basePath = $folder->parent?->getAttribute('path') ?? 'root';
        $newPath = $this->uniqueChildPath($folder->workspace_id, $basePath, $slug, $folder->id);

        return $this->relocateFolderTree($folder, $folder->parent, $trimmed, $newPath);
    }

    public function moveFolder(Folder $folder, ?Folder $targetParent = null): Folder
    {
        $folder = $this->assertMutableFolder($folder);
        $targetParent = $targetParent
            ? $this->assertWorkspaceFolder($folder->workspace_id, $targetParent)
            : $this->workspaceRoot($folder->workspace_id);

        if ($targetParent->id === $folder->id) {
            throw new RuntimeException('A folder cannot be moved into itself.');
        }

        if (Str::startsWith($targetParent->path.'/', $folder->path.'/')) {
            throw new RuntimeException('A folder cannot be moved into its own descendant.');
        }

        $slug = $folder->slug ?: Str::slug($folder->name) ?: 'folder';
        $newPath = $this->uniqueChildPath($folder->workspace_id, $targetParent->path, $slug, $folder->id);

        return $this->relocateFolderTree($folder, $targetParent, $folder->name, $newPath);
    }

    public function copyFolder(Folder $folder, ?Folder $targetParent = null, ?Model $actor = null, ?string $name = null): Folder
    {
        $folder = $this->assertMutableFolder($folder);
        $targetParent = $targetParent
            ? $this->assertWorkspaceFolder($folder->workspace_id, $targetParent)
            : $this->workspaceRoot($folder->workspace_id);

        $rootName = trim($name ?? $folder->name);
        if ($rootName === '') {
            $rootName = $folder->name ?: 'Folder';
        }

        $rootSlug = Str::slug($rootName);
        if ($rootSlug === '') {
            $rootSlug = 'folder';
        }

        $sourceFolders = $this->subtreeFolders($folder);
        $oldPaths = $sourceFolders->mapWithKeys(fn (Folder $item) => [$item->id => $item->path])->all();
        $rootCopyPath = $this->uniqueChildPath($folder->workspace_id, $targetParent->path, $rootSlug);
        $targetPaths = $sourceFolders->mapWithKeys(fn (Folder $item) => [
            $item->id => $this->replacePathPrefix($item->path, $folder->path, $rootCopyPath),
        ])->all();
        $sourceMedia = Media::query()
            ->whereIn('folder_id', $sourceFolders->pluck('id'))
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get();

        $plans = $this->buildFolderMediaCopyPlans($sourceMedia, $oldPaths, $targetPaths);
        $workspace = StorageConfig::findWorkspace($folder->workspace_id);
        $quotaBytes = $plans['quota_bytes'];
        $quotaReserved = false;
        $createdObjects = [];
        $createdMedia = [];

        try {
            if ($workspace && $quotaBytes > 0) {
                $this->storage->increaseUsage($workspace, $quotaBytes);
                $quotaReserved = true;
            }

            foreach ($plans['objects'] as $object) {
                if (! $this->files->copy($object['source'], $object['destination'], $object['disk'], $object['disk'])) {
                    $this->orphans->deleteOrTrack(
                        $object['disk'],
                        $object['destination'],
                        (int) $folder->workspace_id,
                        $object['size'],
                        'folder_copy_rollback',
                    );
                    throw new RuntimeException('Unable to copy folder media on storage.');
                }
                $createdObjects[] = $object;
            }

            $rootCopy = DB::transaction(function () use (
                $folder,
                $targetParent,
                $actor,
                $rootName,
                $sourceFolders,
                $targetPaths,
                $sourceMedia,
                $plans,
                &$createdMedia,
            ): Folder {
                $copies = [];

                $rootCopy = Folder::create([
                    'workspace_id' => $folder->workspace_id,
                    'parent_id' => $targetParent->id,
                    'created_by' => $actor?->getKey(),
                    'name' => $rootName,
                    'slug' => basename($targetPaths[$folder->id]),
                    'path' => $targetPaths[$folder->id],
                    'access_scope' => $folder->access_scope ?? AccessScope::default(),
                    'is_root' => false,
                    'archived_at' => $folder->archived_at,
                ]);
                $copies[$folder->id] = $rootCopy;

                foreach ($sourceFolders->slice(1) as $sourceChild) {
                    $parentCopy = $copies[$sourceChild->getAttribute('parent_id')];
                    $childCopy = Folder::create([
                        'workspace_id' => $sourceChild->getAttribute('workspace_id'),
                        'parent_id' => $parentCopy->id,
                        'created_by' => $actor?->getKey(),
                        'name' => $sourceChild->getAttribute('name'),
                        'slug' => basename($targetPaths[$sourceChild->getKey()]),
                        'path' => $targetPaths[$sourceChild->getKey()],
                        'access_scope' => $sourceChild->access_scope ?? AccessScope::default(),
                        'is_root' => false,
                        'archived_at' => $sourceChild->getAttribute('archived_at'),
                    ]);

                    $copies[$sourceChild->getKey()] = $childCopy;
                }

                foreach ($sourceMedia as $sourceMediaItem) {
                    $targetFolder = $copies[$sourceMediaItem->folder_id];
                    $paths = $plans['media_paths'][$sourceMediaItem->id];

                    $mediaCopy = $sourceMediaItem->replicate([
                        'uuid',
                        'current',
                        'version_group_uuid',
                        'version_number',
                        'previous_version_id',
                        'created_at',
                        'updated_at',
                        'direct_upload_session_uuid',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ]);
                    $mediaCopy->forceFill([
                        'uuid' => (string) Str::uuid(),
                        'folder_id' => $targetFolder->id,
                        'workspace_id' => $targetFolder->workspace_id,
                        'path' => $paths['path'],
                        'direct_upload_session_uuid' => null,
                        'uploaded_by' => $actor?->getKey() ?? $sourceMediaItem->uploaded_by,
                        'access_scope' => $targetFolder->access_scope ?? $sourceMediaItem->access_scope ?? AccessScope::default(),
                        'current' => (bool) $sourceMediaItem->current,
                        'version_group_uuid' => (string) Str::uuid(),
                        'version_number' => 1,
                        'previous_version_id' => null,
                    ] + $this->freshProcessingState());
                    $mediaCopy->save();
                    $createdMedia[] = $mediaCopy;
                }

                return $rootCopy;
            });
        } catch (\Throwable $exception) {
            foreach (array_reverse($createdObjects) as $object) {
                $this->orphans->deleteOrTrack(
                    $object['disk'],
                    $object['destination'],
                    (int) $folder->workspace_id,
                    $object['size'],
                    'folder_copy_rollback',
                );
            }

            if ($quotaReserved && $workspace) {
                $this->storage->decreaseUsage($workspace, $quotaBytes);
            }

            throw $exception;
        }

        foreach ($createdMedia as $mediaCopy) {
            $this->processing->dispatch($mediaCopy);
        }

        return $rootCopy->refresh();
    }

    public function moveFilesToFolder(Model $workspace, array $mediaIds, ?Folder $folder = null): void
    {
        Media::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('id', $mediaIds)
            ->update([
                'folder_id' => $folder?->id,
            ]);
    }

    public function archiveFolder(Folder $folder, bool $applyToMedia = true): void
    {
        $folder->update(['archived_at' => now()]);

        if ($applyToMedia) {
            Media::query()
                ->where('folder_id', $folder->id)
                ->update(['archived_at' => now()]);
        }

        foreach ($folder->children as $child) {
            $this->archiveFolder($child, $applyToMedia);
        }
    }

    public function unarchiveFolder(Folder $folder, bool $applyToMedia = true): void
    {
        $folder->update(['archived_at' => null]);

        if ($applyToMedia) {
            Media::query()
                ->where('folder_id', $folder->id)
                ->update(['archived_at' => null]);
        }

        foreach ($folder->children as $child) {
            $this->unarchiveFolder($child, $applyToMedia);
        }
    }

    public function trashMedia(Media $media): void
    {
        $media->delete();
    }

    public function trashFolder(Folder $folder): void
    {
        $folder = $this->assertMutableFolder($folder);
        $folders = $this->subtreeFolders($folder, withTrashed: true);
        $folderIds = $folders->pluck('id');

        Media::query()
            ->whereIn('folder_id', $folderIds)
            ->whereNull('deleted_at')
            ->get()
            ->each(fn (Media $media) => $media->delete());

        $folders
            ->sortByDesc(fn (Folder $item) => strlen($item->path))
            ->each(function (Folder $item): void {
                if (! $item->trashed()) {
                    $item->delete();
                }
            });
    }

    public function restoreMedia(Media $media): void
    {
        if (method_exists($media, 'restore')) {
            $media->restore();
        }
    }

    public function restoreFolder(Folder $folder): Folder
    {
        $folder = $this->assertRestorableFolder($folder);
        $folders = $this->subtreeFolders($folder, withTrashed: true);
        $folderIds = $folders->pluck('id');

        DB::transaction(function () use ($folders, $folderIds): void {
            $folders
                ->sortBy(fn (Folder $item) => strlen($item->path))
                ->each(function (Folder $item): void {
                    if ($item->trashed()) {
                        $item->restore();
                    }
                });

            Media::onlyTrashed()
                ->whereIn('folder_id', $folderIds)
                ->get()
                ->each(fn (Media $media) => $media->restore());
        });

        return $folder->fresh();
    }

    public function permanentlyDeleteFolder(Folder $folder): void
    {
        $folder = $this->assertWorkspaceFolder($folder->workspace_id, $folder);
        if ($folder->is_root) {
            throw new RuntimeException('The root folder cannot be deleted.');
        }

        $folders = $this->subtreeFolders($folder, withTrashed: true);
        $folderIds = $folders->pluck('id');

        Media::withTrashed()
            ->whereIn('folder_id', $folderIds)
            ->get()
            ->each(fn (Media $media) => $this->deletion->delete($media));

        $folders
            ->sortByDesc(fn (Folder $item) => strlen($item->path))
            ->each(function (Folder $item): void {
                $item->collaborators()->delete();
                $item->forceDelete();
            });
    }

    public function emptyTrash(Model $workspace): void
    {
        Folder::onlyTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->orderByRaw('length(path) desc')
            ->get()
            ->each(fn (Folder $folder) => $this->permanentlyDeleteFolder($folder));

        $trashed = Media::onlyTrashed()
            ->where('workspace_id', $workspace->getKey())
            ->get();

        foreach ($trashed as $media) {
            $this->deletion->delete($media);
        }
    }

    public function resolveOrCreateFolderPath(Model $workspace, string $relativePath): Folder
    {
        $relativePath = trim($relativePath, '/');

        $root = Folder::firstOrCreate(
            [
                'workspace_id' => $workspace->getKey(),
                'is_root' => true,
            ],
            [
                'parent_id' => null,
                'name' => $workspace->getAttribute('name'),
                'slug' => Str::slug($workspace->getAttribute('name').'-root'),
                'path' => 'root',
            ]
        );

        if ($relativePath === '') {
            return $root;
        }

        $segments = explode('/', $relativePath);
        $parent = $root;
        $currentPath = 'root';

        foreach ($segments as $segment) {
            $segment = trim($segment);
            $slug = Str::slug($segment);
            if ($slug === '') {
                $slug = 'folder';
            }
            $currentPath = trim($currentPath.'/'.$slug, '/');

            $folder = Folder::firstOrCreate(
                [
                    'workspace_id' => $workspace->getKey(),
                    'path' => $currentPath,
                ],
                [
                    'parent_id' => $parent->id,
                    'name' => $segment,
                    'slug' => $slug,
                    'is_root' => false,
                ]
            );

            $parent = $folder;
        }

        return $parent;
    }

    private function relocateFolderTree(Folder $folder, ?Folder $targetParent, string $name, string $newPath): Folder
    {
        $oldPath = $folder->path;

        if ($oldPath === $newPath && (int) ($folder->parent_id ?? 0) === (int) ($targetParent?->id ?? 0) && $folder->name === $name) {
            return $folder;
        }

        $folders = $this->subtreeFolders($folder, withTrashed: true);
        $oldPaths = $folders->mapWithKeys(fn (Folder $item) => [$item->id => $item->path])->all();
        $targetPaths = $folders->mapWithKeys(fn (Folder $item) => [
            $item->id => $this->replacePathPrefix($item->path, $oldPath, $newPath),
        ])->all();
        $mediaItems = Media::withTrashed()
            ->whereIn('folder_id', $folders->pluck('id'))
            ->orderBy('id')
            ->get();
        $plans = $this->buildFolderMediaCopyPlans($mediaItems, $oldPaths, $targetPaths, countQuota: false);
        $createdObjects = [];

        try {
            foreach ($plans['objects'] as $object) {
                if (! $this->files->copy($object['source'], $object['destination'], $object['disk'], $object['disk'])) {
                    $this->orphans->deleteOrTrack(
                        $object['disk'],
                        $object['destination'],
                        (int) $folder->workspace_id,
                        $object['size'],
                        'folder_relocation_rollback',
                    );
                    throw new RuntimeException('Unable to relocate folder media on storage.');
                }
                $createdObjects[] = $object;
            }

            DB::transaction(function () use (
                $folder,
                $targetParent,
                $name,
                $newPath,
                $folders,
                $targetPaths,
                $mediaItems,
                $plans,
            ): void {
                $folder->forceFill([
                    'parent_id' => $targetParent?->id,
                    'name' => $name,
                    'slug' => basename($newPath),
                    'path' => $newPath,
                ])->save();

                foreach ($folders->slice(1) as $child) {
                    $child->setAttribute('path', $targetPaths[$child->getKey()]);
                    $child->save();
                }

                foreach ($mediaItems as $media) {
                    $paths = $plans['media_paths'][$media->id];
                    $media->forceFill(['path' => $paths['path']])->save();
                }
            });
        } catch (\Throwable $exception) {
            foreach (array_reverse($createdObjects) as $object) {
                $this->orphans->deleteOrTrack(
                    $object['disk'],
                    $object['destination'],
                    (int) $folder->workspace_id,
                    $object['size'],
                    'folder_relocation_rollback',
                );
            }

            throw $exception;
        }

        foreach ($createdObjects as $object) {
            $this->orphans->deleteOrTrack(
                $object['disk'],
                $object['source'],
                (int) $folder->workspace_id,
                $object['size'],
                'folder_relocation_cleanup',
            );
        }

        return $folder->refresh();
    }

    /**
     * @param  EloquentCollection<int, Media>  $mediaItems
     * @param  array<int, string>  $oldPaths
     * @param  array<int, string>  $targetPaths
     * @return array{
     *   objects: array<int, array{source:string,destination:string,disk:Disk,size:int|null}>,
     *   media_paths: array<int, array{path:string|null}>,
     *   quota_bytes:int
     * }
     */
    private function buildFolderMediaCopyPlans(
        EloquentCollection $mediaItems,
        array $oldPaths,
        array $targetPaths,
        bool $countQuota = true,
    ): array {
        $objects = [];
        $mediaPaths = [];
        $quotaBytes = 0;
        $destinations = [];

        foreach ($mediaItems as $media) {
            $oldFolderPath = $oldPaths[$media->folder_id] ?? 'root';
            $targetFolderPath = $targetPaths[$media->folder_id] ?? $oldFolderPath;
            $newPath = $this->relocateStoredPathForFolder($media->path, $oldFolderPath, $targetFolderPath);
            $mediaPaths[$media->id] = ['path' => $newPath];

            if ($media->disk instanceof Disk) {
                foreach ([
                    [$media->path, $newPath, $media->size ? (int) $media->size : null],
                ] as [$source, $destination, $size]) {
                    if (! is_string($source)
                        || $source === ''
                        || ! is_string($destination)
                        || $destination === ''
                        || $source === $destination
                        || $this->isExternalPath($source)) {
                        continue;
                    }

                    $key = $media->disk->value."\0".$destination;
                    if (isset($destinations[$key])) {
                        continue;
                    }
                    $destinations[$key] = true;

                    $objects[] = [
                        'source' => $source,
                        'destination' => $destination,
                        'disk' => $media->disk,
                        'size' => $size,
                    ];

                    if ($countQuota && is_int($size) && $size > 0) {
                        $quotaBytes += $size;
                    }
                }
            }
        }

        return [
            'objects' => $objects,
            'media_paths' => $mediaPaths,
            'quota_bytes' => $quotaBytes,
        ];
    }

    private function subtreeFolders(Folder $folder, bool $withTrashed = false): EloquentCollection
    {
        $query = $withTrashed ? Folder::withTrashed() : Folder::query();

        return $query
            ->where('workspace_id', $folder->workspace_id)
            ->where(function ($builder) use ($folder) {
                $builder->where('id', $folder->id)
                    ->orWhere('path', 'like', $folder->path.'/%');
            })
            ->orderBy('path')
            ->get();
    }

    private function uniqueChildPath(int $workspaceId, string $basePath, string $slug, ?int $ignoreFolderId = null): string
    {
        $slug = trim($slug, '/');
        $candidate = trim($basePath.'/'.$slug, '/');
        $suffix = 2;

        while ($this->folderPathExists($workspaceId, $candidate, $ignoreFolderId)) {
            $candidate = trim($basePath.'/'.$slug.'-'.$suffix, '/');
            $suffix++;
        }

        return $candidate;
    }

    private function folderPathExists(int $workspaceId, string $path, ?int $ignoreFolderId = null): bool
    {
        $query = Folder::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->where('path', $path);

        if ($ignoreFolderId) {
            $query->where('id', '!=', $ignoreFolderId);
        }

        return $query->exists();
    }

    private function replacePathPrefix(string $path, string $oldPrefix, string $newPrefix): string
    {
        if ($path === $oldPrefix) {
            return $newPrefix;
        }

        return preg_replace(
            '/^'.preg_quote($oldPrefix, '/').'\//',
            trim($newPrefix, '/').'/',
            $path,
            1
        ) ?? $path;
    }

    private function relocateMediaPathForFolder(Media $media, string $oldFolderPath, string $newFolderPath): string
    {
        return (string) $this->relocateStoredPathForFolder($media->path, $oldFolderPath, $newFolderPath);
    }

    private function relocateStoredPathForFolder(?string $storedPath, string $oldFolderPath, string $newFolderPath): ?string
    {
        if (! is_string($storedPath)) {
            return null;
        }

        $path = trim($storedPath, '/');
        if ($path === '' || $this->isExternalPath($path)) {
            return $storedPath;
        }

        $oldRelative = $this->relativeFolderPath($oldFolderPath);
        $newRelative = $this->relativeFolderPath($newFolderPath);
        $filename = basename($path);
        $directory = trim(dirname($path), '/');
        $directory = $directory === '.' ? '' : $directory;

        if ($oldRelative === '') {
            return trim(implode('/', array_filter([$directory, $newRelative, $filename], fn ($segment) => $segment !== '')), '/');
        }

        // Replace the last complete old-folder segment inside the directory so
        // derivative subdirectories such as "pdf/.thumbnails" move together
        // with their owning folder rather than remaining shared with the source.
        $paddedDirectory = '/'.$directory.'/';
        $marker = '/'.$oldRelative.'/';
        $position = strrpos($paddedDirectory, $marker);

        if ($position !== false) {
            $before = trim(substr($paddedDirectory, 0, $position), '/');
            $after = trim(substr($paddedDirectory, $position + strlen($marker)), '/');

            return trim(implode('/', array_filter(
                [$before, $newRelative, $after, $filename],
                fn ($segment) => $segment !== '',
            )), '/');
        }

        return trim(implode('/', array_filter([$directory, $newRelative, $filename], fn ($segment) => $segment !== '')), '/');
    }

    private function relativeFolderPath(string $folderPath): string
    {
        if ($folderPath === 'root') {
            return '';
        }

        return trim((string) Str::of($folderPath)->after('root/')->trim('/'), '/');
    }

    private function assertMutableFolder(Folder $folder): Folder
    {
        $folder = $this->assertWorkspaceFolder($folder->workspace_id, $folder);

        if ($folder->is_root) {
            throw new RuntimeException('The root folder cannot be modified.');
        }

        return $folder;
    }

    private function assertRestorableFolder(Folder $folder): Folder
    {
        $resolved = Folder::withTrashed()
            ->where('workspace_id', $folder->workspace_id)
            ->findOrFail($folder->id);

        if ($resolved->is_root) {
            throw new RuntimeException('The root folder cannot be restored.');
        }

        return $resolved;
    }

    private function assertWorkspaceFolder(int $workspaceId, Folder $folder): Folder
    {
        return Folder::withTrashed()
            ->where('workspace_id', $workspaceId)
            ->findOrFail($folder->id);
    }

    private function workspaceRoot(int $workspaceId): Folder
    {
        return Folder::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_root', true)
            ->firstOrFail();
    }

    private function isExternalPath(?string $path): bool
    {
        if (! is_string($path) || trim($path) === '') {
            return false;
        }

        return str_starts_with($path, '//')
            || filter_var($path, FILTER_VALIDATE_URL) !== false;
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
}
