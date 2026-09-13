<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaLibrary;
use Tetranyble\Storage\Modules\Media\Infrastructure\Application\MediaLibraryService;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;

final class EloquentMediaLibrary implements MediaLibrary
{
    public function __construct(private readonly MediaLibraryService $library) {}

    public function createWorkspaceRoot(object $workspace): object
    {
        return $this->library->createWorkspaceRoot($this->model($workspace));
    }

    public function createFolder(object $workspace, string $name, ?object $parent = null): object
    {
        return $this->library->createFolder(
            $this->model($workspace),
            $name,
            $parent === null ? null : $this->folder($parent),
        );
    }

    public function trashMedia(object $media): void
    {
        $this->library->trashMedia($this->media($media));
    }

    public function restoreMedia(object $media): void
    {
        $this->library->restoreMedia($this->media($media));
    }

    public function emptyTrash(object $workspace): void
    {
        $this->library->emptyTrash($this->model($workspace));
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected Eloquent model.');
        }

        return $value;
    }

    private function media(object $value): Media
    {
        if (! $value instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

        return $value;
    }

    private function folder(object $value): Folder
    {
        if (! $value instanceof Folder) {
            throw new InvalidArgumentException('Expected Folder model.');
        }

        return $value;
    }
}
