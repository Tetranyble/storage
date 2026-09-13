<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Handlers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Tetranyble\Storage\Modules\Access\Application\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Modules\Access\Domain\Enums\AccessScope;
use Tetranyble\Storage\Modules\Access\Infrastructure\Persistence\Eloquent\Queries\ResourceVisibilityQuery;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Storage\Infrastructure\StorageService;
use Tetranyble\Storage\Modules\Workspace\Application\Queries\BrowseWorkspace;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\EloquentReadResources;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\ReadPagination;
use Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support\WorkspaceReadProjector;

final class BrowseWorkspaceHandler
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly ResourceAccessControl $access,
        private readonly ResourceVisibilityQuery $visibility,
        private readonly WorkspaceReadProjector $projector,
        private readonly ReadPagination $pagination,
        private readonly EloquentReadResources $resources,
    ) {}

    public function handle(BrowseWorkspace $query): array
    {
        $workspace = $this->resources->model($query->workspace, 'workspace');
        $actor = $query->actor ? $this->resources->model($query->actor, 'actor') : null;
        $this->visibility->forget($workspace, $actor);
        $root = $this->ensureRootFolder($workspace);
        $current = $this->resolveFolder($workspace, $query->relativePath) ?? $root;
        if ($actor) {
            $this->access->authorizeView($workspace, $current, $actor);
        }
        $sortBy = in_array($query->sortBy, ['name', 'created_at', 'updated_at'], true) ? $query->sortBy : 'name';
        $sortDir = strtolower($query->sortDir) === 'desc' ? 'desc' : 'asc';

        $folderQuery = Folder::query()->where('workspace_id', $workspace->getKey())->where('parent_id', $current->getKey())
            ->whereNull('deleted_at')->orderBy($sortBy === 'name' ? 'name' : $sortBy, $sortDir);
        $this->visibility->folders($folderQuery, $workspace, $actor);
        $folders = $folderQuery->get()->map(function (Folder $folder) use ($actor, $workspace): array {
            $dto = $this->projector->folder($folder);
            $dto['effective_role'] = $actor ? $this->visibility->effectiveFolderRole($workspace, $folder, $actor)?->value : null;

            return $dto;
        })->values();

        $filesQuery = Media::query()->where('workspace_id', $workspace->getKey())->whereNull('deleted_at');
        if ($current->is_root) {
            $filesQuery->where(fn ($q) => $q->whereNull('folder_id')->orWhere('folder_id', $current->id));
        } else {
            $filesQuery->where('folder_id', $current->id);
        }
        $search = trim($query->search);
        if ($search !== '') {
            $filesQuery->where(fn ($q) => $q->where('original_name', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%")->orWhere('path', 'like', "%{$search}%"));
        }
        $this->visibility->media($filesQuery, $workspace, $actor);
        $map = ['name' => 'original_name', 'created_at' => 'created_at', 'updated_at' => 'updated_at'];
        $filesQuery->orderBy($map[$sortBy] ?? 'original_name', $sortDir);
        $paginator = $filesQuery->with(['shares' => fn ($q) => $q->latest()])
            ->paginate($query->perPage, ['*'], 'page', $query->page);
        $usage = $this->storage->usage($workspace);

        return [
            'path' => $this->projector->relativePath($current), 'search' => $search,
            'sort' => ['by' => $sortBy, 'dir' => $sortDir],
            'currentFolder' => ['id' => $current->id, 'name' => $current->name, 'path' => $this->projector->relativePath($current)],
            'breadcrumbs' => $this->breadcrumbs($workspace, $current), 'folders' => $folders,
            'files' => collect($paginator->items())->map(fn (Media $media) => $this->projector->file($media))->values(),
            'pagination' => $this->pagination->lengthAware($paginator),
            'usage' => ['used_bytes' => $usage->used->bytes, 'quota_bytes' => $usage->quota->bytes,
                'remaining_bytes' => $usage->remaining()->bytes, 'percent' => $usage->percentage(), 'near_limit' => $usage->isNearLimit()],
        ];
    }

    /** @return list<array{id:int,name:string,path:string}> */
    private function breadcrumbs(Model $workspace, Folder $current): array
    {
        $path = trim((string) $current->path, '/');
        if ($path === '' || $path === 'root') {
            return [[
                'id' => (int) $current->id,
                'name' => 'Root',
                'path' => '',
            ]];
        }

        $segments = explode('/', $path);
        $prefixes = [];
        $prefix = '';
        foreach ($segments as $segment) {
            $prefix = $prefix === '' ? $segment : $prefix.'/'.$segment;
            $prefixes[] = $prefix;
        }

        $folders = Folder::query()
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('path', $prefixes)
            ->get()
            ->keyBy('path');

        $items = [];
        foreach ($prefixes as $prefixPath) {
            $folder = $folders->get($prefixPath);
            if (! $folder instanceof Folder) {
                continue;
            }
            $items[] = [
                'id' => (int) $folder->id,
                'name' => $folder->is_root ? 'Root' : (string) $folder->name,
                'path' => $this->projector->relativePath($folder),
            ];
        }

        return $items;
    }

    private function ensureRootFolder(Model $workspace): Folder
    {
        return Folder::firstOrCreate(['workspace_id' => $workspace->getKey(), 'is_root' => true], [
            'name' => $workspace->getAttribute('name'), 'slug' => Str::slug($workspace->getAttribute('name').'-root'), 'path' => 'root',
            'parent_id' => null, 'created_by' => null, 'access_scope' => AccessScope::default(),
        ]);
    }

    private function resolveFolder(Model $workspace, string $relativePath): ?Folder
    {
        $relativePath = trim($relativePath, '/');

        return Folder::query()->where('workspace_id', $workspace->getKey())
            ->where('path', $relativePath === '' ? 'root' : 'root/'.$relativePath)->first();
    }
}
