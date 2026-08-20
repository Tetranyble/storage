<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Domain\FileSystem\StorageService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\CollaboratorRole;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Events\FolderCreated;
use Tetranyble\Storage\Events\FolderShared;
use Tetranyble\Storage\Events\FolderTrashed;
use Tetranyble\Storage\Events\MediaPermanentlyDeleted;
use Tetranyble\Storage\Events\MediaRestored;
use Tetranyble\Storage\Events\MediaShared;
use Tetranyble\Storage\Events\MediaTrashed;
use Tetranyble\Storage\Events\MediaUploaded;
use Tetranyble\Storage\Models\Activity;
use Tetranyble\Storage\Models\CollaboratorGrant;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Models\MediaShare;
use Tetranyble\Storage\Models\ResourceStar;
use Illuminate\Http\UploadedFile;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use RuntimeException;

class WorkspaceFileManagerService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageService $storage,
        private readonly MediaLibraryService $library,
        private readonly MediaShareService $shares,
        private readonly ResourceAccessControl $access,
        private readonly MediaService $mediaService,
        private readonly ActivityLogger $activityLogger,
        private readonly MediaUploader $uploader,
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
            ->orderBy($sortBy === 'name' ? 'name' : 'deleted_at', $sortDir)
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

    public function uploadFiles(Model $workspace, array $uploadedFiles, ?Folder $folder = null, ?Model $actor = null): Collection
    {
        $folder = $folder ? $this->assertWorkspaceFolder($workspace, $folder) : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        return collect($uploadedFiles)->map(function (UploadedFile $file) use ($workspace, $folder, $actor) {
            return $this->uploadSingleFile($workspace, $file, $folder, $actor);
        });
    }

    public function authorizeUploadToFolder(Model $workspace, Folder $folder, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);
        $this->access->authorizeEdit($workspace, $folder, $actor);
    }

    public function createFolder(Model $workspace, string $name, ?Folder $parent = null, ?Model $actor = null, ?AccessScope $scope = null): Folder
    {
        $parent = $parent ? $this->assertWorkspaceFolder($workspace, $parent) : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $parent, $actor);
        }

        $folder = $this->library->createFolder($workspace, $name, $parent);
        $folder->forceFill([
            'created_by' => $actor?->id,
            'access_scope' => $scope ?? $parent->access_scope ?? AccessScope::default(),
        ])->save();

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.created',
            description: 'Folder created.',
            actor: $actor,
            meta: ['parent_id' => $parent->id],
            changes: ['after' => $this->folderSnapshot($folder)],
        );

        Event::dispatch(new FolderCreated($folder->refresh(), $actor));

        return $folder->refresh();
    }

    public function renameFolder(Model $workspace, Folder $folder, string $name, ?Model $actor = null): Folder
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        $before = $this->folderSnapshot($folder);
        $renamed = $this->library->renameFolder($folder, $name);

        $this->logActivity(
            subject: $renamed,
            type: 'storage.folder.renamed',
            description: 'Folder renamed.',
            actor: $actor,
            changes: ['before' => $before, 'after' => $this->folderSnapshot($renamed)],
        );

        return $renamed;
    }

    public function moveFolder(Model $workspace, Folder $folder, ?Folder $targetParent = null, ?Model $actor = null): Folder
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);
        $targetParent = $targetParent
            ? $this->assertWorkspaceFolder($workspace, $targetParent)
            : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
            $this->access->authorizeEdit($workspace, $targetParent, $actor);
        }

        $before = $this->folderSnapshot($folder);
        $moved = $this->library->moveFolder($folder, $targetParent);

        $this->logActivity(
            subject: $moved,
            type: 'storage.folder.moved',
            description: 'Folder moved.',
            actor: $actor,
            meta: ['target_parent_id' => $targetParent->id],
            changes: ['before' => $before, 'after' => $this->folderSnapshot($moved)],
        );

        return $moved;
    }

    public function copyFolder(
        Model $workspace,
        Folder $folder,
        ?Folder $targetParent = null,
        ?Model $actor = null,
        ?string $name = null,
    ): Folder {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);
        $targetParent = $targetParent
            ? $this->assertWorkspaceFolder($workspace, $targetParent)
            : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeView($workspace, $folder, $actor);
            $this->access->authorizeEdit($workspace, $targetParent, $actor);
        }

        $copy = $this->library->copyFolder($folder, $targetParent, $actor, $name);

        $this->logActivity(
            subject: $copy,
            type: 'storage.folder.copied',
            description: 'Folder copied.',
            actor: $actor,
            meta: ['source_folder_id' => $folder->id, 'target_parent_id' => $targetParent->id],
            changes: ['after' => $this->folderSnapshot($copy)],
        );

        return $copy;
    }

    public function renameMedia(Model $workspace, Media $media, string $name, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $trimmed = trim($name);
        if ($trimmed === '') {
            throw new RuntimeException('File name cannot be empty.');
        }

        $disk = $media->disk instanceof Disk ? $media->disk : Disk::default();
        $oldPath = (string) $media->path;
        $newFilename = $this->buildRenamedFilename($oldPath, $trimmed);
        $newPath = trim(dirname($oldPath), '/');
        $newPath = ($newPath === '' || $newPath === '.') ? $newFilename : $newPath.'/'.$newFilename;

        if ($oldPath !== '' && $newPath !== $oldPath) {
            $moved = $this->files->move($oldPath, $newPath, $disk, $disk);
            if (! $moved) {
                throw new RuntimeException('Unable to rename file on storage.');
            }
            $media->path = $newPath;
        }

        $media->original_name = $newFilename;
        $media->description = $trimmed;
        $media->save();

        $this->logActivity(
            subject: $media,
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

    public function moveMediaToFolder(Model $workspace, Media $media, ?Folder $targetFolder, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        $targetFolder = $targetFolder
            ? $this->assertWorkspaceFolder($workspace, $targetFolder)
            : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
            $this->access->authorizeEdit($workspace, $targetFolder, $actor);
        }

        $oldPath = (string) $media->path;
        $disk = $media->disk instanceof Disk ? $media->disk : Disk::default();
        $currentFolder = $media->folder_id ? Folder::query()->find($media->folder_id) : null;
        $newPath = $this->relocateMediaPathBetweenFolders($media, $currentFolder, $targetFolder);

        if ($oldPath !== '' && $newPath !== $oldPath) {
            $moved = $this->files->move($oldPath, $newPath, $disk, $disk);
            if (! $moved) {
                throw new RuntimeException('Unable to move file on storage.');
            }
            $media->path = $newPath;
        }

        $media->folder_id = $targetFolder->id;
        $media->access_scope = $targetFolder->access_scope ?? $media->access_scope ?? AccessScope::default();
        $media->save();

        $this->logActivity(
            subject: $media,
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

    public function setCurrentMedia(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        abort_unless($media->mediable_id !== null && $media->mediable_type !== null, 422, 'Standalone media has no model default.');

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $selected = $this->mediaService->setCurrentMedia($media);

        $this->logActivity(
            subject: $selected,
            type: 'storage.media.current.selected',
            description: 'Media selected as the current item.',
            actor: $actor,
            meta: ['purpose' => $selected->use?->value],
        );

        return $selected;
    }

    public function starMedia(Model $workspace, Media $media, Model $actor): ResourceStar
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        $this->access->authorizeView($workspace, $media, $actor);

        $star = ResourceStar::firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'starable_type' => $media::class,
            'starable_id' => $media->id,
        ]);

        $this->logActivity(
            subject: $media,
            type: 'storage.media.starred',
            description: 'Media starred.',
            actor: $actor,
            meta: ['star_id' => $star->id],
        );

        return $star;
    }

    public function unstarMedia(Model $workspace, Media $media, Model $actor): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        $this->access->authorizeView($workspace, $media, $actor);

        ResourceStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->where('starable_type', $media::class)
            ->where('starable_id', $media->id)
            ->delete();

        $this->logActivity(
            subject: $media,
            type: 'storage.media.unstarred',
            description: 'Media unstarred.',
            actor: $actor,
        );
    }

    public function starFolder(Model $workspace, Folder $folder, Model $actor): ResourceStar
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);
        $this->access->authorizeView($workspace, $folder, $actor);

        $star = ResourceStar::firstOrCreate([
            'workspace_id' => $workspace->id,
            'user_id' => $actor->id,
            'starable_type' => $folder::class,
            'starable_id' => $folder->id,
        ]);

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.starred',
            description: 'Folder starred.',
            actor: $actor,
            meta: ['star_id' => $star->id],
        );

        return $star;
    }

    public function unstarFolder(Model $workspace, Folder $folder, Model $actor): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);
        $this->access->authorizeView($workspace, $folder, $actor);

        ResourceStar::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $actor->id)
            ->where('starable_type', $folder::class)
            ->where('starable_id', $folder->id)
            ->delete();

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.unstarred',
            description: 'Folder unstarred.',
            actor: $actor,
        );
    }

    public function starredPayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
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

    public function sharedWithMePayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
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

    public function recentPayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 25,
    ): array {
        $activities = $this->storageActivitiesQuery($workspace)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

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

    public function activityPayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
        $activities = $this->storageActivitiesQuery($workspace)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

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

    public function listMediaVersions(Model $workspace, Media $media, ?Model $actor = null): array
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeView($workspace, $media, $actor);
        }

        $versions = $this->mediaService->revisionsFor($media)
            ->map(fn (Media $item) => $this->toFileDto($item))
            ->values();
        $history = $this->mediaService->revisionActivityFor($media)
            ->map(fn (\Tetranyble\Storage\Models\Activity $activity) => $this->toActivityDto($activity))
            ->values();

        return [
            'media_id' => $media->id,
            'versions' => $versions,
            'history' => $history,
        ];
    }

    public function uploadMediaRevision(Model $workspace, Media $media, UploadedFile $file, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        return $this->mediaService->createRevisionFromUpload($media, $file, $actor?->id);
    }

    public function restoreMediaRevision(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        return $this->mediaService->restoreRevision($media, $actor?->id);
    }

    public function trashMedia(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $this->library->trashMedia($media);
        Event::dispatch(new MediaTrashed($media, $actor));

        $this->logActivity(
            subject: $media,
            type: 'storage.media.trashed',
            description: 'Media moved to trash.',
            actor: $actor,
        );
    }

    public function trashFolder(Model $workspace, Folder $folder, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        $this->library->trashFolder($folder);
        Event::dispatch(new FolderTrashed($folder, $actor));

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.trashed',
            description: 'Folder moved to trash.',
            actor: $actor,
        );
    }

    public function restoreMedia(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $this->library->restoreMedia($media);
        Event::dispatch(new MediaRestored($media, $actor));

        $this->logActivity(
            subject: $media,
            type: 'storage.media.restored',
            description: 'Media restored from trash.',
            actor: $actor,
        );
    }

    public function restoreFolder(Model $workspace, Folder $folder, ?Model $actor = null): Folder
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        $restored = $this->library->restoreFolder($folder);

        $this->logActivity(
            subject: $restored,
            type: 'storage.folder.restored',
            description: 'Folder restored from trash.',
            actor: $actor,
        );

        return $restored;
    }

    public function permanentlyDeleteMedia(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $deletedId = $media->id;
        $workspaceId = $media->workspace_id ? (int) $media->workspace_id : null;
        $path = $media->path;

        $this->mediaService->deleteMediaItem($media);

        Event::dispatch(new MediaPermanentlyDeleted($deletedId, $workspaceId, $path, $actor));
    }

    public function permanentlyDeleteFolder(Model $workspace, Folder $folder, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.deleted',
            description: 'Folder permanently deleted.',
            actor: $actor,
        );

        $this->library->permanentlyDeleteFolder($folder);
    }

    public function bulkTrash(
        Model $workspace,
        array $mediaIds = [],
        array $folderIds = [],
        ?Model $actor = null,
    ): array {
        $trashedMedia = 0;
        $trashedFolders = 0;

        foreach ($mediaIds as $id) {
            $media = Media::query()->where('workspace_id', $workspace->id)->find($id);
            if (! $media) {
                continue;
            }
            if ($actor && ! $this->access->canEdit($workspace, $media, $actor)) {
                continue;
            }
            $this->library->trashMedia($media);
            $this->logActivity($media, 'storage.media.trashed', 'Media moved to trash.', $actor);
            $trashedMedia++;
        }

        foreach ($folderIds as $id) {
            $folder = Folder::query()->where('workspace_id', $workspace->id)->find($id);
            if (! $folder || $folder->is_root) {
                continue;
            }
            if ($actor && ! $this->access->canEdit($workspace, $folder, $actor)) {
                continue;
            }
            $this->library->trashFolder($folder);
            $this->logActivity($folder, 'storage.folder.trashed', 'Folder moved to trash.', $actor);
            $trashedFolders++;
        }

        return ['trashed_media' => $trashedMedia, 'trashed_folders' => $trashedFolders];
    }

    public function bulkRestore(
        Model $workspace,
        array $mediaIds = [],
        array $folderIds = [],
        ?Model $actor = null,
    ): array {
        $restoredMedia = 0;
        $restoredFolders = 0;

        foreach ($mediaIds as $id) {
            $media = Media::onlyTrashed()->where('workspace_id', $workspace->id)->find($id);
            if (! $media) {
                continue;
            }
            $this->library->restoreMedia($media);
            $this->logActivity($media, 'storage.media.restored', 'Media restored from trash.', $actor);
            $restoredMedia++;
        }

        foreach ($folderIds as $id) {
            $folder = Folder::onlyTrashed()->where('workspace_id', $workspace->id)->find($id);
            if (! $folder) {
                continue;
            }
            $this->library->restoreFolder($folder);
            $this->logActivity($folder, 'storage.folder.restored', 'Folder restored from trash.', $actor);
            $restoredFolders++;
        }

        return ['restored_media' => $restoredMedia, 'restored_folders' => $restoredFolders];
    }

    public function bulkPermanentlyDelete(
        Model $workspace,
        array $mediaIds = [],
        array $folderIds = [],
        ?Model $actor = null,
    ): array {
        $deletedMedia = 0;
        $deletedFolders = 0;

        foreach ($mediaIds as $id) {
            $media = Media::withTrashed()->where('workspace_id', $workspace->id)->find($id);
            if (! $media) {
                continue;
            }
            if ($actor && ! $this->access->canEdit($workspace, $media, $actor)) {
                continue;
            }
            $this->mediaService->deleteMediaItem($media);
            $deletedMedia++;
        }

        foreach ($folderIds as $id) {
            $folder = Folder::withTrashed()->where('workspace_id', $workspace->id)->find($id);
            if (! $folder || $folder->is_root) {
                continue;
            }
            if ($actor && ! $this->access->canEdit($workspace, $folder, $actor)) {
                continue;
            }
            $this->library->permanentlyDeleteFolder($folder);
            $deletedFolders++;
        }

        return ['deleted_media' => $deletedMedia, 'deleted_folders' => $deletedFolders];
    }

    public function bulkMove(
        Model $workspace,
        array $mediaIds,
        ?Folder $targetFolder,
        ?Model $actor = null,
    ): int {
        $targetFolder = $targetFolder
            ? $this->assertWorkspaceFolder($workspace, $targetFolder)
            : $this->ensureRootFolder($workspace);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $targetFolder, $actor);
        }

        $moved = 0;
        foreach ($mediaIds as $id) {
            $media = Media::query()->where('workspace_id', $workspace->id)->find($id);
            if (! $media) {
                continue;
            }
            if ($actor && ! $this->access->canEdit($workspace, $media, $actor)) {
                continue;
            }
            $this->moveMediaToFolder($workspace, $media, $targetFolder, $actor);
            $moved++;
        }

        return $moved;
    }

    public function emptyTrash(Model $workspace): void
    {
        $this->library->emptyTrash($workspace);
    }

    public function createShare(
        Model $workspace,
        Media $media,
        ?Model $user,
        string $accessLevel = 'download',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?Model $actor = null,
    ): MediaShare {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        $share = $this->shares->createForMedia(
            workspace: $workspace,
            media: $media,
            accessLevel: $accessLevel,
            ttlMinutes: $ttlMinutes,
            maxDownloads: $maxDownloads,
            password: $password,
            createdBy: $actor?->id ?? $user?->id,
        );

        $this->logActivity(
            subject: $media,
            type: 'storage.media.shared',
            description: 'Media share created.',
            actor: $actor ?? $user,
            meta: [
                'share_id' => $share->id,
                'access_level' => $share->access_level,
                'expires_at' => $share->expires_at,
                'max_downloads' => $share->max_downloads,
                'requires_password' => $share->requires_password,
            ],
        );

        Event::dispatch(new MediaShared($media, $share, $actor ?? $user));

        return $share;
    }

    public function createFolderShare(
        Model $workspace,
        Folder $folder,
        ?Model $user,
        string $accessLevel = 'view',
        ?int $ttlMinutes = null,
        ?int $maxDownloads = null,
        ?string $password = null,
        ?Model $actor = null,
    ): MediaShare {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $folder, $actor);
        }

        $share = $this->shares->createForFolder(
            workspace: $workspace,
            folder: $folder,
            accessLevel: $accessLevel,
            ttlMinutes: $ttlMinutes,
            maxDownloads: $maxDownloads,
            password: $password,
            createdBy: $actor?->id ?? $user?->id,
        );

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.shared',
            description: 'Folder share link created.',
            actor: $actor ?? $user,
            meta: [
                'share_id' => $share->id,
                'access_level' => $share->access_level,
                'expires_at' => $share->expires_at,
            ],
        );

        Event::dispatch(new FolderShared($folder, $share, $actor ?? $user));

        return $share;
    }

    public function revokeFolderShare(Model $workspace, Folder $folder, MediaShare $share, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $folder, $actor);
        }

        if ($share->workspace_id !== $workspace->id
            || $share->shareable_type !== Folder::class
            || (int) $share->shareable_id !== (int) $folder->id) {
            abort(404);
        }

        $share->delete();

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.share_revoked',
            description: 'Folder share link revoked.',
            actor: $actor,
            meta: ['share_id' => $share->id],
        );
    }

    public function revokeShare(Model $workspace, Media $media, MediaShare $share, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, true);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        if ($share->workspace_id !== $workspace->id
            || $share->shareable_type !== Media::class
            || (int) $share->shareable_id !== (int) $media->id) {
            abort(404);
        }

        $share->delete();

        $this->logActivity(
            subject: $media,
            type: 'storage.media.share_revoked',
            description: 'Media share revoked.',
            actor: $actor,
            meta: ['share_id' => $share->id],
        );
    }

    public function grantFolderAccess(
        Model $workspace,
        Folder $folder,
        Model $user,
        CollaboratorRole $role,
        ?Model $actor = null,
    ): CollaboratorGrant {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $folder, $actor);
        }

        $grant = $this->access->grant($workspace, $folder, $user, $role, $actor);

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.access_granted',
            description: 'Folder collaborator granted.',
            actor: $actor,
            meta: [
                'grantee_user_id' => $user->id,
                'role' => $role,
                'grant_id' => $grant->id,
            ],
        );

        return $grant;
    }

    public function revokeFolderAccess(Model $workspace, Folder $folder, Model $user, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $folder, $actor);
        }

        $role = CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('collaboratable_type', $folder::class)
            ->where('collaboratable_id', $folder->id)
            ->where('user_id', $user->id)
            ->value('role');

        $this->access->revoke($workspace, $folder, $user);

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.access_revoked',
            description: 'Folder collaborator revoked.',
            actor: $actor,
            meta: [
                'grantee_user_id' => $user->id,
                'role' => $role instanceof \BackedEnum ? $role->value : $role,
            ],
        );
    }

    public function grantMediaAccess(
        Model $workspace,
        Media $media,
        Model $user,
        CollaboratorRole $role,
        ?Model $actor = null,
    ): CollaboratorGrant {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        $grant = $this->access->grant($workspace, $media, $user, $role, $actor);

        $this->logActivity(
            subject: $media,
            type: 'storage.media.access_granted',
            description: 'Media collaborator granted.',
            actor: $actor,
            meta: [
                'grantee_user_id' => $user->id,
                'role' => $role,
                'grant_id' => $grant->id,
            ],
        );

        return $grant;
    }

    public function revokeMediaAccess(Model $workspace, Media $media, Model $user, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        $role = CollaboratorGrant::query()
            ->where('workspace_id', $workspace->id)
            ->where('collaboratable_type', $media::class)
            ->where('collaboratable_id', $media->id)
            ->where('user_id', $user->id)
            ->value('role');

        $this->access->revoke($workspace, $media, $user);

        $this->logActivity(
            subject: $media,
            type: 'storage.media.access_revoked',
            description: 'Media collaborator revoked.',
            actor: $actor,
            meta: [
                'grantee_user_id' => $user->id,
                'role' => $role instanceof \BackedEnum ? $role->value : $role,
            ],
        );
    }

    public function setFolderAccessScope(Model $workspace, Folder $folder, AccessScope $scope, ?Model $actor = null): Folder
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $folder, $actor);
        }

        $before = $folder->access_scope?->value ?? AccessScope::default()->value;
        $updated = $this->access->setScope($workspace, $folder, $scope);

        $this->logActivity(
            subject: $updated,
            type: 'storage.folder.scope_changed',
            description: 'Folder access scope changed.',
            actor: $actor,
            changes: [
                'before' => ['access_scope' => $before],
                'after' => ['access_scope' => $updated->access_scope?->value ?? AccessScope::default()->value],
            ],
        );

        return $updated;
    }

    public function setMediaAccessScope(Model $workspace, Media $media, AccessScope $scope, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);

        if ($actor) {
            $this->access->authorizeManagePermissions($workspace, $media, $actor);
        }

        $before = $media->access_scope?->value ?? AccessScope::default()->value;
        $updated = $this->access->setScope($workspace, $media, $scope);

        $this->logActivity(
            subject: $updated,
            type: 'storage.media.scope_changed',
            description: 'Media access scope changed.',
            actor: $actor,
            changes: [
                'before' => ['access_scope' => $before],
                'after' => ['access_scope' => $updated->access_scope?->value ?? AccessScope::default()->value],
            ],
        );

        return $updated;
    }

    public function assertWorkspaceFolder(Model $workspace, Folder $folder, bool $allowTrashed = false): Folder
    {
        $query = Folder::query()->where('workspace_id', $workspace->id)->where('id', $folder->id);
        if ($allowTrashed) {
            $query->withTrashed();
        }

        $resolved = $query->first();
        if (! $resolved) {
            abort(404);
        }

        return $resolved;
    }

    public function assertWorkspaceMedia(Model $workspace, Media $media, bool $allowTrashed): Media
    {
        $query = Media::query()->where('workspace_id', $workspace->id)->where('id', $media->id);
        if ($allowTrashed) {
            $query->withTrashed();
        } else {
            $query->whereNull('deleted_at');
        }

        $resolved = $query->first();
        if (! $resolved) {
            abort(404);
        }

        return $resolved;
    }

    public function resolveFolderById(Model $workspace, ?int $folderId): ?Folder
    {
        if (! $folderId) {
            return null;
        }

        $folder = Folder::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $folderId)
            ->first();

        if (! $folder) {
            abort(404);
        }

        return $folder;
    }

    public function resolveFolderByRelativePath(Model $workspace, string $relativePath): ?Folder
    {
        $relativePath = trim($relativePath, '/');
        $path = $relativePath === '' ? 'root' : 'root/'.$relativePath;

        return Folder::query()
            ->where('workspace_id', $workspace->id)
            ->where('path', $path)
            ->first();
    }

    public function toRelativePath(Folder $folder): string
    {
        if ($folder->is_root || $folder->path === 'root') {
            return '';
        }

        return (string) Str::of($folder->path)->after('root/')->trim('/');
    }

    private function ensureRootFolder(Model $workspace): Folder
    {
        return Folder::firstOrCreate(
            [
                'workspace_id' => $workspace->id,
                'is_root' => true,
            ],
            [
                'name' => $workspace->name,
                'slug' => Str::slug($workspace->name.'-root'),
                'path' => 'root',
                'parent_id' => null,
                'created_by' => null,
                'access_scope' => AccessScope::default(),
            ]
        );
    }

    private function filesQueryForFolder(Model $workspace, Folder $currentFolder, string $search = '')
    {
        $query = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->latest('created_at');

        if ($currentFolder->is_root) {
            $query->where(function ($q) use ($currentFolder) {
                $q->whereNull('folder_id')
                    ->orWhere('folder_id', $currentFolder->id);
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

    private function uploadSingleFile(Model $workspace, UploadedFile $file, Folder $folder, ?Model $actor = null): Media
    {
        $media = $this->uploader->uploadUploadedFile($file, MediaUploadOptions::forStandalone(
            workspaceId: $workspace->id,
            userId: $actor?->id,
            folderId: $folder->id,
            purpose: MediaPurpose::GENERAL,
            disk: Disk::PRIVATE,
            directory: 'workspace',
            module: 'file-centre',
            temporary: false,
            label: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME),
            attribution: 'workspace-file',
        ));

        Event::dispatch(new MediaUploaded($media, $actor));

        return $media;
    }

    private function buildRenamedFilename(string $oldPath, string $newName): string
    {
        $newName = trim($newName);
        $oldExtension = pathinfo($oldPath, PATHINFO_EXTENSION);
        $providedExtension = pathinfo($newName, PATHINFO_EXTENSION);
        $baseName = pathinfo($newName, PATHINFO_FILENAME);

        $safeBase = Str::slug($baseName);
        if ($safeBase === '') {
            $safeBase = 'file';
        }

        $extension = $providedExtension !== '' ? $providedExtension : $oldExtension;

        return $extension !== '' ? "{$safeBase}.{$extension}" : $safeBase;
    }

    private function toFileDto(Media $media, bool $includeDeletedAt = false): array
    {
        $name = $media->original_name
            ?: basename((string) $media->path)
            ?: 'untitled';

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

        $mediaQuery = Media::query()
            ->where('workspace_id', $workspace->id)
            ->whereNull('deleted_at')
            ->where(fn ($q) => $q->where('original_name', 'like', "%{$query}%")
                ->orWhere('description', 'like', "%{$query}%")
                ->orWhere('path', 'like', "%{$query}%"))
            ->orderBy($sortBy, $sortDir);

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

    private function storageActivitiesQuery(Model $workspace)
    {
        return Activity::query()
            ->where('workspace_id', $workspace->id)
            ->where('type', 'like', 'storage.%')
            ->with('subject');
    }

    private function logActivity(
        Media|Folder $subject,
        string $type,
        string $description,
        ?Model $actor = null,
        array $meta = [],
        array $changes = [],
    ): void {
        $this->activityLogger->log(
            subject: $subject,
            type: $type,
            description: $description,
            actor: $actor,
            meta: $meta,
            changes: $changes,
            workspaceId: $subject->workspace_id ? (int) $subject->workspace_id : null,
        );
    }

    private function folderSnapshot(Folder $folder): array
    {
        return [
            'id' => $folder->id,
            'name' => $folder->name,
            'path' => $folder->path,
            'parent_id' => $folder->parent_id,
            'access_scope' => $folder->access_scope?->value ?? AccessScope::default()->value,
        ];
    }

    private function toActivityDto(\Tetranyble\Storage\Models\Activity $activity): array
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

        $column = $map[$sortBy] ?? 'original_name';
        $query->orderBy($column, $sortDir);
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

        return trim(implode('/', array_filter([$baseDirectory, $newRelative, $filename], fn ($segment) => $segment !== '')), '/');
    }

    private function folderRelativePath(Folder $folder): string
    {
        if ($folder->is_root || $folder->path === 'root') {
            return '';
        }

        return trim((string) Str::of($folder->path)->after('root/')->trim('/'), '/');
    }
}
