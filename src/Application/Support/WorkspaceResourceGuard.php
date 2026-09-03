<?php

namespace Tetranyble\Storage\Application\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;

class WorkspaceResourceGuard
{
    public function media(Model $workspace, Media $media, bool $allowTrashed = false): Media
    {
        if ((string) $media->workspace_id !== (string) $workspace->getKey()
            || (! $allowTrashed && method_exists($media, 'trashed') && $media->trashed())) {
            $this->notFound(Media::class, $media->getKey());
        }

        return $media;
    }

    public function folder(Model $workspace, Folder $folder, bool $allowTrashed = false): Folder
    {
        if ((string) $folder->workspace_id !== (string) $workspace->getKey()
            || (! $allowTrashed && method_exists($folder, 'trashed') && $folder->trashed())) {
            $this->notFound(Folder::class, $folder->getKey());
        }

        return $folder;
    }

    public function folderById(Model $workspace, ?int $folderId, bool $allowTrashed = false): ?Folder
    {
        if ($folderId === null) {
            return null;
        }

        $query = $allowTrashed ? Folder::withTrashed() : Folder::query();
        $folder = $query
            ->where('workspace_id', $workspace->getKey())
            ->find($folderId);

        if (! $folder instanceof Folder) {
            $this->notFound(Folder::class, $folderId);
        }

        return $folder;
    }

    private function notFound(string $model, mixed $id): never
    {
        throw (new ModelNotFoundException())->setModel($model, [$id]);
    }
}
