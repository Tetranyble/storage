<?php

namespace Tetranyble\Storage\Domain\Media;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Models\CollaboratorGrant;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\ResourceStar;

/**
 * Read-only workspace file-centre queries and DTO projection.
 *
 * Keeping query composition out of WorkspaceFileManagerService prevents reads,
 * mutations, access orchestration and presentation mapping from growing together.
 */
class WorkspaceFileQueryService
{
    public function __construct(
        private readonly StorageService $storage,
        private readonly ResourceAccessControl $access,
        private readonly ActivityFeed $activityFeed,
        private readonly MediaVersioningService $versioning,
    ) {}

    public function indexPayload(
        Model $workspace,
        string $relativePath = '',
        string $search = '',
        ?Model $actor = null,
        string $sortBy = 'name',
        string $sortDir = 'asc',
        int $page = 1,
        int $perPage = 50,
    ): array {
        $root = $this->ensureRootFolder($workspace);
        $currentFolder = $this->resolveFolderByRelativePath($workspace, $relativePath) ?? $root;

        if ($actor) {
            $this->access->authorizeView($workspace, $currentFolder, $actor);
        }

        $allowedSorts = ['name', 'created_at', 'updated_at'];
        $sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'name';
        $sortDir = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';

        $folders = Folder::query()
            ->where('workspace_id', $workspace->id)
            ->where('parent_id', $currentFolder->id)
            ->whereNull('deleted_at')
            ->orderBy($sortBy === 'name' ? 'name' : $sortBy, $sortDir)
            ->get()
            ->filter(fn (Folder $folder) => ! $actor || $this->access->canView($workspace, $folder, $actor))
            ->map(fn (Folder $folder) => [
                'id' => $folder->id,
                'uuid' => $folder->uuid,
                'name' => $folder->name,
                'path' => $this->toRelativePath($folder),
                'access_scope' => $folder->access_scope?->value ?? AccessScope::default()->value,
                'effective_role' => $actor ? $this->access->effectiveRole($workspace, $folder, $actor)?->value : null,
                'is_archived' => $folder->is_archived,
                'created_at' => optional($folder->created_at)?->toIso8601String(),
            ])
            ->values();

        $filesQuery = $this->filesQueryForFolder($workspace, $currentFolder, $search);
        $this->applySortToMediaQuery($filesQuery, $sortBy, $sortDir);

        $filesPaginator = $filesQuery
            ->with(['shares' => fn ($q) => $q->latest()])
            ->paginate($perPage, ['*'], 'page', $page);

        $files = collect($filesPaginator->items())
            ->filter(fn (Media $media) => ! $actor || $this->access->canView($workspace, $media, $actor))
            ->map(fn (Media $media) => $this->toFileDto($media))
            ->values();

        $usage = $this->storage->usage($workspace);

        return [
            'path' => $this->toRelativePath($currentFolder),
            'search' => $search,
            'sort' => ['by' => $sortBy, 'dir' => $sortDir],
            'currentFolder' => [
                'id' => $currentFolder->id,
                'name' => $currentFolder->name,
                'path' => $this->toRelativePath($currentFolder),
            ],
            'breadcrumbs' => $this->breadcrumbs($currentFolder),
            'folders' => $folders,
            'files' => $files,
            'pagination' => $this->paginationMeta($filesPaginator),
            'usage' => [
                'used_bytes' => $usage->usedBytes,
                'quota_bytes' => $usage->quotaBytes,
                'remaining_bytes' => $usage->remainingBytes(),
                'percent' => $usage->percentage(),
                'near_limit' => $usage->isNearLimit(),
            ],
        ];
    }

    public function trashPayload(
        Model $workspace,
        string $sortBy = 'deleted_at',
        string $sortDir = 'desc',
        int $page = 1,
        int $perPage = 50,
    ): array {
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $folders = Folder::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderBy($sortBy === 'name' ? 'name' : 'deleted_at', $sortDir)
            ->paginate($perPage, ['*'], 'folder_page', $page);

        $files = Media::onlyTrashed()
            ->where('workspace_id', $workspace->id)
            ->orderBy($sortBy === 'name' ? 'original_name' : 'deleted_at', $sortDir)
            ->paginate($perPage, ['*'], 'file_page', $page);

        return [
            'folders' => collect($folders->items())->map(fn (Folder $f) => $this->toFolderDto($f, true))->values(),
            'files' => collect($files->items())->map(fn (Media $m) => $this->toFileDto($m, true))->values(),
            'pagination' => [
                'folders' => $this->paginationMeta($folders),
                'files' => $this->paginationMeta($files),
            ],
        ];
    }

    public function starredPayload(Model $workspace, Model $actor, int $page = 1, int $perPage = 50): array
    {
        $stars = ResourceStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->latest()
            ->get();

        $folders = $stars
            ->where('starable_type', Folder::class)
            ->map(fn (ResourceStar $star) => $star->starable)
            ->filter(fn ($folder) => $folder instanceof Folder && $this->access->canView($workspace, $folder, $actor))
            ->map(fn (Folder $folder) => $this->toFolderDto($folder))
            ->values();

        $files = $stars
            ->where('starable_type', Media::class)
            ->map(fn (ResourceStar $star) => $star->starable)
            ->filter(fn ($media) => $media instanceof Media && $this->access->canView($workspace, $media, $actor))
            ->map(fn (Media $media) => $this->toFileDto($media))
            ->values();

        return [
            'folders' => $this->paginateCollection($folders, $page, $perPage),
            'files' => $this->paginateCollection($files, $page, $perPage),
        ];
    }

    public function sharedWithMePayload(Model $workspace, Model $actor, int $page = 1, int $perPage = 50): array
    {
        $grants = CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->latest()
            ->get();

        $folders = $grants
            ->where('collaboratable_type', Folder::class)
            ->map(fn (CollaboratorGrant $grant) => Folder::query()->find($grant->collaboratable_id))
            ->filter(fn ($folder) => $folder instanceof Folder && $this->access->canView($workspace, $folder, $actor))
            ->map(fn (Folder $folder) => $this->toFolderDto($folder))
            ->values();

        $files = $grants
            ->where('collaboratable_type', Media::class)
            ->map(fn (CollaboratorGrant $grant) => Media::query()->find($grant->collaboratable_id))
            ->filter(fn ($media) => $media instanceof Media && $this->access->canView($workspace, $media, $actor))
            ->map(fn (Media $media) => $this->toFileDto($media))
            ->values();

        return [
            'folders' => $this->paginateCollection($folders, $page, $perPage),
            'files' => $this->paginateCollection($files, $page, $perPage),
        ];
    }

    public function recentPayload(Model $workspace, Model $actor, int $page = 1, int $perPage = 25): array
    {
        $activities = $this->activityFeed->forWorkspace($workspace);

        $recentFiles = $activities
            ->where('subject_type', Media::class)
            ->map(fn (Activity $activity) => Media::query()->find($activity->subject_id))
            ->filter(fn ($media) => $media instanceof Media && $this->access->canView($workspace, $media, $actor))
            ->unique(fn (Media $media) => $media->id)
            ->map(fn (Media $media) => $this->toFileDto($media))
            ->values();

        $recentFolders = $activities
            ->where('subject_type', Folder::class)
            ->map(fn (Activity $activity) => Folder::query()->find($activity->subject_id))
            ->filter(fn ($folder) => $folder instanceof Folder && $this->access->canView($workspace, $folder, $actor))
            ->unique(fn (Folder $folder) => $folder->id)
            ->map(fn (Folder $folder) => $this->toFolderDto($folder))
            ->values();

        return [
            'folders' => $this->paginateCollection($recentFolders, $page, $perPage),
            'files' => $this->paginateCollection($recentFiles, $page, $perPage),
        ];
    }

    public function activityPayload(Model $workspace, Model $actor, int $page = 1, int $perPage = 50): array
    {
        $activities = $this->activityFeed->paginateWorkspace($workspace, $page, $perPage);

        $items = collect($activities->items())
            ->filter(function (Activity $activity) use ($workspace, $actor): bool {
                $subject = $activity->subject;

                return ($subject instanceof Media || $subject instanceof Folder)
                    && $this->access->canView($workspace, $subject, $actor);
            })
            ->map(fn (Activity $activity) => $this->toActivityDto($activity))
            ->values();

        return [
            'activities' => $items,
            'pagination' => $this->paginationMeta($activities),
        ];
    }

    public function mediaVersionsPayload(Model $workspace, Media $media, ?Model $actor = null): array
    {
        $media = $this->workspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeView($workspace, $media, $actor);
        }

        return [
            'media_id' => $media->id,
            'versions' => $this->versioning->versions($media)
                ->map(fn (Media $item) => $this->toFileDto($item))
                ->values(),
            'history' => $this->versioning->activity($media)
                ->map(fn (Activity $activity) => $this->toActivityDto($activity))
                ->values(),
        ];
    }

    public function searchPayload(
        Model $workspace,
        string $query,
        ?Model $actor = null,
        int $page = 1,
        int $perPage = 50,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
    ): array {
        $query = trim($query);
        $sortDir = strtolower($sortDir) === 'asc' ? 'asc' : 'desc';

        $folderQuery = Folder::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('name', 'like', "%{$query}%")
                ->orWhere('path', 'like', "%{$query}%"))
            ->orderBy('name', $sortDir);

        $folders = $folderQuery->get()
            ->filter(fn (Folder $folder) => ! $actor || $this->access->canView($workspace, $folder, $actor))
            ->map(fn (Folder $folder) => $this->toFolderDto($folder))
            ->values();

        $allowedSorts = ['name', 'created_at', 'updated_at', 'size', 'mime_type'];
        $sortBy = in_array($sortBy, $allowedSorts, true) ? $sortBy : 'updated_at';
        $column = $sortBy === 'name' ? 'original_name' : $sortBy;

        $mediaQuery = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('original_name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->orWhere('path', 'like', "%{$query}%"))
            ->orderBy($column, $sortDir);

        $filesPaginator = $mediaQuery->paginate($perPage, ['*'], 'page', $page);

        $files = collect($filesPaginator->items())
            ->filter(fn (Media $media) => ! $actor || $this->access->canView($workspace, $media, $actor))
            ->map(fn (Media $media) => $this->toFileDto($media))
            ->values();

        return [
            'query' => $query,
            'sort' => ['by' => $sortBy, 'dir' => $sortDir],
            'folders' => $folders,
            'files' => $files,
            'pagination' => $this->paginationMeta($filesPaginator),
        ];
    }

    private function workspaceMedia(Model $workspace, Media $media, bool $withTrashed): Media
    {
        $query = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereKey($media->getKey());

        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query->firstOrFail();
    }

    private function ensureRootFolder(Model $workspace): Folder
    {
        return Folder::firstOrCreate(
            ['workspace_id' => $workspace->id, 'is_root' => true],
            [
                'name' => $workspace->name,
                'slug' => Str::slug($workspace->name.'-root'),
                'path' => 'root',
                'parent_id' => null,
                'created_by' => null,
                'access_scope' => AccessScope::default(),
            ],
        );
    }

    private function resolveFolderByRelativePath(Model $workspace, string $relativePath): ?Folder
    {
        $relativePath = trim($relativePath, '/');
        $path = $relativePath === '' ? 'root' : 'root/'.$relativePath;

        return Folder::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $path)
            ->first();
    }

    private function toRelativePath(Folder $folder): string
    {
        if ($folder->is_root || $folder->path === 'root') {
            return '';
        }

        return (string) Str::of($folder->path)->after('root/')->trim('/');
    }

    private function filesQueryForFolder(Model $workspace, Folder $currentFolder, string $search = '')
    {
        $query = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->latest('created_at');

        if ($currentFolder->is_root) {
            $query->where(function ($q) use ($currentFolder) {
                $q->whereNull('folder_id')->orWhere('folder_id', $currentFolder->id);
            });
        } else {
            $query->where('folder_id', $currentFolder->id);
        }

        $search = trim($search);
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('original_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('path', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    private function toFileDto(Media $media, bool $includeDeletedAt = false): array
    {
        $name = $media->original_name ?: basename((string) $media->path) ?: 'untitled';

        $dto = [
            'id' => $media->id,
            'uuid' => $media->uuid,
            'name' => $name,
            'description' => $media->description,
            'path' => $media->path,
            'mime_type' => $media->mime_type,
            'size' => (int) ($media->size ?? 0),
            'current' => (bool) $media->current,
            'version_number' => (int) ($media->version_number ?? 1),
            'version_group_uuid' => $media->version_group_uuid,
            'previous_version_id' => $media->previous_version_id,
            'url' => $media->url,
            'signed_url' => $media->signed_url,
            'folder_id' => $media->folder_id,
            'access_scope' => $media->access_scope?->value ?? AccessScope::default()->value,
            'effective_role' => null,
            'uploaded_by' => $media->uploaded_by,
            'created_at' => optional($media->created_at)?->toIso8601String(),
            'updated_at' => optional($media->updated_at)?->toIso8601String(),
            'shares' => $media->relationLoaded('shares')
                ? $media->shares->map(fn (MediaShare $share) => [
                    'id' => $share->id,
                    'token' => $share->token,
                    'access_level' => $share->access_level,
                    'expires_at' => optional($share->expires_at)?->toIso8601String(),
                    'max_downloads' => $share->max_downloads,
                    'downloads_count' => $share->downloads_count,
                    'requires_password' => (bool) $share->requires_password,
                ])->values()->all()
                : [],
        ];

        if ($includeDeletedAt) {
            $dto['deleted_at'] = optional($media->deleted_at)?->toIso8601String();
        }

        return $dto;
    }

    private function toFolderDto(Folder $folder, bool $includeDeletedAt = false): array
    {
        $dto = [
            'id' => $folder->id,
            'uuid' => $folder->uuid,
            'name' => $folder->name,
            'path' => $this->toRelativePath($folder),
            'access_scope' => $folder->access_scope?->value ?? AccessScope::default()->value,
            'is_archived' => $folder->is_archived,
            'created_at' => optional($folder->created_at)?->toIso8601String(),
            'updated_at' => optional($folder->updated_at)?->toIso8601String(),
        ];

        if ($includeDeletedAt) {
            $dto['deleted_at'] = optional($folder->deleted_at)?->toIso8601String();
        }

        return $dto;
    }

    private function breadcrumbs(Folder $folder): array
    {
        $items = [];
        $cursor = $folder;

        while ($cursor) {
            array_unshift($items, [
                'id' => $cursor->id,
                'name' => $cursor->is_root ? 'Root' : $cursor->name,
                'path' => $this->toRelativePath($cursor),
            ]);
            $cursor = $cursor->parent;
        }

        return $items;
    }

    private function toActivityDto(Activity $activity): array
    {
        return [
            'id' => $activity->id,
            'uuid' => $activity->uuid,
            'type' => $activity->type,
            'description' => $activity->description,
            'subject_id' => $activity->subject_id,
            'subject_type' => $activity->subject_type,
            'actor_user_id' => $activity->user_id,
            'meta' => is_array($activity->meta) ? $activity->meta : [],
            'changes' => is_array($activity->changes) ? $activity->changes : [],
            'created_at' => optional($activity->created_at)?->toIso8601String(),
        ];
    }

    private function paginationMeta(\Illuminate\Contracts\Pagination\LengthAwarePaginator $paginator): array
    {
        return [
            'total' => $paginator->total(),
            'per_page' => $paginator->perPage(),
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }

    private function paginateCollection(\Illuminate\Support\Collection $collection, int $page, int $perPage): array
    {
        $total = $collection->count();
        $items = $collection->forPage($page, $perPage)->values();
        $lastPage = (int) ceil($total / $perPage) ?: 1;

        return [
            'data' => $items,
            'pagination' => [
                'total' => $total,
                'per_page' => $perPage,
                'current_page' => $page,
                'last_page' => $lastPage,
                'from' => $total > 0 ? (($page - 1) * $perPage) + 1 : null,
                'to' => $total > 0 ? min($page * $perPage, $total) : null,
            ],
        ];
    }

    private function applySortToMediaQuery($query, string $sortBy, string $sortDir): void
    {
        $map = [
            'name' => 'original_name',
            'size' => 'size',
            'type' => 'mime_type',
            'created_at' => 'created_at',
            'updated_at' => 'updated_at',
        ];

        $query->orderBy($map[$sortBy] ?? 'original_name', $sortDir);
    }
}
