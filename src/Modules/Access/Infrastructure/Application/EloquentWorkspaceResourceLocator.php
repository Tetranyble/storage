<?php

namespace Tetranyble\Storage\Modules\Access\Infrastructure\Application;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Access\Application\Contracts\WorkspaceResourceLocator;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;

final class EloquentWorkspaceResourceLocator implements WorkspaceResourceLocator
{
    public function media(object $workspace, object $media, bool $allowTrashed = false): object
    {
        $workspace = $this->model($workspace, 'workspace');
        if (! $media instanceof Media
            || (string) $media->workspace_id !== (string) $workspace->getKey()
            || (! $allowTrashed && $media->trashed())) {
            $this->notFound($media::class, $media instanceof Model ? $media->getKey() : null);
        }

        return $media;
    }

    public function folder(object $workspace, object $folder, bool $allowTrashed = false): object
    {
        $workspace = $this->model($workspace, 'workspace');
        if (! $folder instanceof Folder
            || (string) $folder->workspace_id !== (string) $workspace->getKey()
            || (! $allowTrashed && $folder->trashed())) {
            $this->notFound($folder::class, $folder instanceof Model ? $folder->getKey() : null);
        }

        return $folder;
    }

    public function folderById(object $workspace, ?int $folderId, bool $allowTrashed = false): ?object
    {
        if ($folderId === null) {
            return null;
        }
        $workspace = $this->model($workspace, 'workspace');
        $query = $allowTrashed ? Folder::withTrashed() : Folder::query();
        $folder = $query->where('workspace_id', $workspace->getKey())->find($folderId);
        if (! $folder instanceof Folder) {
            $this->notFound(Folder::class, $folderId);
        }

        return $folder;
    }

    private function model(object $value, string $label): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException("Expected {$label} to be an Eloquent model.");
        }

        return $value;
    }

    private function notFound(string $model, mixed $id): never
    {
        throw new ResourceNotFoundException(sprintf('%s [%s] was not found in the current workspace.', class_basename($model), (string) $id));
    }
}
