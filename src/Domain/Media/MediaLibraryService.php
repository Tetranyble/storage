<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class MediaLibraryService
{
    public function __construct(
        protected FileSystemContract $files,
        protected StorageService $storage,
    ) {
    }

    public function createWorkspaceRoot(Model $workspace): Folder
    {
        $folder = Folder::firstOrCreate([
            'workspace_id' => $workspace->id,
            'path' => 'root',
        ], [
            'parent_id' => null,
            'name' => $workspace->name,
            'slug' => Str::slug($workspace->name . '-root'),
            'is_root' => true,
        ]);

        $prefix = "workspaces/{$workspace->id}";
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
        $path = $this->uniqueChildPath($workspace->id, $basePath, $slug);

        return Folder::create([
            'workspace_id' => $workspace->id,
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

        $basePath = $folder->parent?->path ?? 'root';
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
        $copies = [];

        return DB::transaction(function () use ($folder, $targetParent, $actor, $rootName, $rootSlug, $sourceFolders, $oldPaths, &$copies) {
            $rootCopyPath = $this->uniqueChildPath($folder->workspace_id, $targetParent->path, $rootSlug);

            $rootCopy = Folder::create([
                'workspace_id' => $folder->workspace_id,
                'parent_id' => $targetParent->id,
                'created_by' => $actor?->id,
                'name' => $rootName,
                'slug' => basename($rootCopyPath),
                'path' => $rootCopyPath,
                'access_scope' => $folder->access_scope ?? AccessScope::default(),
                'is_root' => false,
                'archived_at' => $folder->archived_at,
            ]);
            $copies[$folder->id] = $rootCopy;

            foreach ($sourceFolders->slice(1) as $sourceChild) {
                $parentCopy = $copies[$sourceChild->parent_id];
                $childSlug = $sourceChild->slug ?: Str::slug($sourceChild->name) ?: 'folder';
                $childPath = trim($parentCopy->path.'/'.$childSlug, '/');

                $childCopy = Folder::create([
                    'workspace_id' => $sourceChild->workspace_id,
                    'parent_id' => $parentCopy->id,
                    'created_by' => $actor?->id,
                    'name' => $sourceChild->name,
                    'slug' => $childSlug,
                    'path' => $childPath,
                    'access_scope' => $sourceChild->access_scope ?? AccessScope::default(),
                    'is_root' => false,
                    'archived_at' => $sourceChild->archived_at,
                ]);

                $copies[$sourceChild->id] = $childCopy;
            }

            $sourceFolderIds = $sourceFolders->pluck('id');

            Media::query()
                ->whereIn('folder_id', $sourceFolderIds)
                ->whereNull('deleted_at')
                ->orderBy('id')
                ->get()
                ->each(function (Media $sourceMedia) use ($copies, $oldPaths, $actor): void {
                    $sourceFolder = Folder::findOrFail($sourceMedia->folder_id);
                    $targetFolder = $copies[$sourceFolder->id];
                    $newStoredPath = $this->relocateMediaPathForFolder($sourceMedia, $oldPaths[$sourceFolder->id], $targetFolder->path);

                    if ($sourceMedia->path && ! $this->isExternalPath($sourceMedia->path) && $sourceMedia->disk instanceof Disk) {
                        $copied = $this->files->copy($sourceMedia->path, $newStoredPath, $sourceMedia->disk, $sourceMedia->disk);
                        if (! $copied) {
                            throw new RuntimeException('Unable to copy folder media on storage.');
                        }
                    } else {
                        $newStoredPath = $sourceMedia->path;
                    }

                    $mediaCopy = $sourceMedia->replicate([
                        'uuid',
                        'created_at',
                        'updated_at',
                        'deleted_at',
                    ]);
                    $mediaCopy->forceFill([
                        'folder_id' => $targetFolder->id,
                        'workspace_id' => $targetFolder->workspace_id,
                        'path' => $newStoredPath,
                        'uploaded_by' => $actor?->id ?? $sourceMedia->uploaded_by,
                        'access_scope' => $targetFolder->access_scope ?? $sourceMedia->access_scope ?? AccessScope::default(),
                    ]);
                    $mediaCopy->save();
                });

            return $rootCopy->refresh();
        });
    }

    public function moveFilesToFolder(Model $workspace, array $mediaIds, ?Folder $folder = null): void
    {
        Media::query()
            ->where('workspace_id', $workspace->id)
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
            ->each(fn (Media $media) => app(\Tetranyble\Storage\Domain\FileSystem\MediaService::class)->deleteMediaItem($media));

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
            ->where('workspace_id', $workspace->id)
            ->orderByRaw('length(path) desc')
            ->get()
            ->each(fn (Folder $folder) => $this->permanentlyDeleteFolder($folder));

        $trashed = Media::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->get();

        foreach ($trashed as $media) {
            $bytes = (int) $media->size;
            $disk = $media->disk;

            $path = $media->path;
            if ($path && ! filter_var($path, FILTER_VALIDATE_URL) && $disk) {
                $this->files->disk($disk)->delete($path);
            }

            if ($bytes > 0) {
                $this->storage->decreaseUsage($workspace, $bytes);
            }

            $media->forceDelete();
        }
    }

    public function streamDownload(Media $media)
    {
        $disk = $media->disk;
        $path = $media->path;

        $stream = $this->files->disk($disk)->readStream($path);

        if (! $stream) {
            abort(404, 'File not found.');
        }

        $mime = $media->mime_type ?: $this->files->mimeType($path, $disk) ?? 'application/octet-stream';
        $filename = basename($path) ?: 'download';

        return response()->stream(function () use ($stream) {
            while (! feof($stream)) {
                echo fread($stream, 8192);
                flush();
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function resolveOrCreateFolderPath(Model $workspace, string $relativePath): Folder
    {
        $relativePath = trim($relativePath, '/');

        $root = Folder::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'is_root' => true,
            ],
            [
                'parent_id' => null,
                'name' => $workspace->name,
                'slug' => Str::slug($workspace->name . '-root'),
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
            $currentPath = trim($currentPath . '/' . $slug, '/');

            $folder = Folder::firstOrCreate(
                [
                    'workspace_id' => $workspace->id,
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
        $folderIds = $folders->pluck('id');

        return DB::transaction(function () use ($folder, $targetParent, $name, $newPath, $oldPath, $folders, $oldPaths, $folderIds) {
            $folder->forceFill([
                'parent_id' => $targetParent?->id,
                'name' => $name,
                'slug' => basename($newPath),
                'path' => $newPath,
            ])->save();

            foreach ($folders->slice(1) as $child) {
                $child->path = $this->replacePathPrefix($oldPaths[$child->id], $oldPath, $newPath);
                $child->save();
            }

            Media::withTrashed()
                ->whereIn('folder_id', $folderIds)
                ->get()
                ->each(function (Media $media) use ($oldPaths): void {
                    $currentFolder = Folder::withTrashed()->findOrFail($media->folder_id);
                    $oldFolderPath = $oldPaths[$media->folder_id] ?? $currentFolder->path;
                    $newMediaPath = $this->relocateMediaPathForFolder($media, $oldFolderPath, $currentFolder->path);

                    if ($newMediaPath === (string) $media->path) {
                        return;
                    }

                    if ($media->path && ! $this->isExternalPath($media->path) && $media->disk instanceof Disk) {
                        $moved = $this->files->move($media->path, $newMediaPath, $media->disk, $media->disk);
                        if (! $moved) {
                            throw new RuntimeException('Unable to move folder media on storage.');
                        }
                    }

                    $media->path = $newMediaPath;
                    $media->save();
                });

            return $folder->refresh();
        });
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
        $path = trim((string) $media->path, '/');
        if ($path === '' || $this->isExternalPath($path)) {
            return $path;
        }

        $oldRelative = $this->relativeFolderPath($oldFolderPath);
        $newRelative = $this->relativeFolderPath($newFolderPath);
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

        return trim(implode('/', array_filter([$baseDirectory, $newRelative, $filename], fn ($segment) => $segment !== '')), '/');
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

        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }
}
