<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\MediaLibraryService;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;

/**
 * Resolves storage placement, package folder placement and deterministic path
 * fragments independently from Media persistence/orchestration.
 */
final class MediaStoragePathResolver
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StoragePlacementPolicy $placement,
        private readonly MediaLibraryService $library,
        private readonly QuarantineStoragePolicy $quarantine,
    ) {}

    public function disk(MediaUploadOptions $options, ?string $externalUrl = null): Disk
    {
        if ($externalUrl) {
            return $this->diskForExternalUrl($externalUrl);
        }

        $disk = $options->disk
            ?? $this->placement->preferredDisk($options)
            ?? $this->files->getDefaultDisk();

        $this->quarantine->assertStorageSafe($disk);

        return $disk;
    }

    public function uploadDirectory(MediaUploadOptions $options, ?Model $workspace): string
    {
        $workspaceSegment = $workspace
            ? 'workspaces/'.($workspace->uuid ?: $workspace->id)
            : 'global';
        $moduleSegment = trim($options->module ?: ($options->directory ?: 'media'), '/');
        $moduleSegment = $moduleSegment !== '' ? $moduleSegment : 'media';
        $dateSegment = now()->format('Y/m/d');

        $segments = [$workspaceSegment, $moduleSegment, $dateSegment];

        if ($options->folderId && $workspace) {
            $folder = $this->workspaceFolder($workspace, $options->folderId);
            $relativePath = trim((string) Str::of($folder->path)->after('root/')->trim('/'), '/');
            if ($relativePath !== '' && $relativePath !== 'root') {
                $segments[] = $relativePath;
            }
        } elseif ($options->model) {
            $base = method_exists($options->model, 'mediaBaseDirectory')
                ? $options->model->mediaBaseDirectory()
                : Str::kebab(class_basename($options->model)).'s';
            $segments[] = $base;
            $segments[] = (string) $options->model->getKey();
            $segments[] = Str::slug($options->purpose->value);
        } elseif ($options->directory) {
            $extra = trim($options->directory, '/');
            if ($extra !== '' && $extra !== $moduleSegment) {
                $segments[] = $extra;
            }
            $segments[] = Str::slug($options->purpose->value);
        } else {
            $segments[] = Str::slug($options->purpose->value);
        }

        return trim(implode('/', array_filter($segments)), '/');
    }

    public function folderForOptions(Model $workspace, MediaUploadOptions $options): ?Folder
    {
        if ($options->folderId) {
            return $this->workspaceFolder($workspace, $options->folderId);
        }

        if (in_array((string) $options->module, (array) config('tetranyble-storage.placement.root_modules', []), true)) {
            return $this->library->resolveOrCreateFolderPath($workspace, '');
        }

        if ($options->model) {
            return $this->library->resolveOrCreateFolderPath(
                $workspace,
                $this->folderPathForModel($options->model, $options->purpose),
            );
        }

        return $this->library->resolveOrCreateFolderPath(
            $workspace,
            $this->folderPathForStandalone($options),
        );
    }

    public function storedFilename(string $originalName, bool $preserveFilename = false): string
    {
        $name = pathinfo($originalName, PATHINFO_FILENAME);
        $extension = pathinfo($originalName, PATHINFO_EXTENSION);
        $safeBase = Str::slug($name);

        if ($safeBase === '') {
            $safeBase = 'file';
        }

        if (! $preserveFilename) {
            $safeBase .= '-'.Str::lower(Str::random(8));
        }

        return $extension !== '' ? "{$safeBase}.{$extension}" : $safeBase;
    }

    public function diskForExternalUrl(string $url): Disk
    {
        $lower = strtolower($url);

        if (str_contains($lower, 'youtube.com') || str_contains($lower, 'youtu.be')) {
            return Disk::YOUTUBE;
        }

        if (str_contains($lower, 'vimeo.com')) {
            return Disk::VIMEO;
        }

        return Disk::PUBLIC;
    }

    private function workspaceFolder(Model $workspace, int $folderId): Folder
    {
        return Folder::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($folderId);
    }

    private function folderPathForModel(Model $model, MediaPurpose $purpose): string
    {
        $base = method_exists($model, 'mediaBaseDirectory')
            ? $model->mediaBaseDirectory()
            : Str::kebab(class_basename($model)).'s';

        return trim("{$base}/{$model->getKey()}/".Str::slug($purpose->value), '/');
    }

    private function folderPathForStandalone(MediaUploadOptions $options): string
    {
        $module = trim($options->module ?: 'free', '/');

        return trim($module.'/'.Str::slug($options->purpose->value), '/');
    }
}
