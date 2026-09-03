<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
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
        private readonly MediaLibraryService $library,
        private readonly MediaShareService $shares,
        private readonly ResourceAccessControl $access,
        private readonly MediaService $mediaService,
        private readonly MediaDeletionService $deletion,
        private readonly MediaRelocationService $relocation,
        private readonly CurrentMediaSelectionService $currentSelection,
        private readonly WorkspaceFileQueryService $queries,
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
        return $this->queries->indexPayload(
            $workspace, $relativePath, $search, $actor, $sortBy, $sortDir, $page, $perPage,
        );
    }

    public function trashPayload(
        Model $workspace,
        string $sortBy = 'deleted_at',
        string $sortDir = 'desc',
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->queries->trashPayload($workspace, $sortBy, $sortDir, $page, $perPage);
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

    public function authorizeViewMedia(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        $this->access->authorizeView($workspace, $media, $actor);
    }

    public function authorizeEditMedia(Model $workspace, Media $media, ?Model $actor = null): void
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        $this->access->authorizeEdit($workspace, $media, $actor);
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

        return $this->relocation->rename($media, $name, $actor);
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

        return $this->relocation->move($media, $targetFolder, $actor);
    }

    public function setCurrentMedia(Model $workspace, Media $media, ?Model $actor = null): Media
    {
        $media = $this->assertWorkspaceMedia($workspace, $media, false);
        abort_unless($media->mediable_id !== null && $media->mediable_type !== null, 422, 'Standalone media has no model default.');

        if ($actor) {
            $this->access->authorizeEdit($workspace, $media, $actor);
        }

        $selected = $this->currentSelection->select($media);

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
        return $this->queries->starredPayload($workspace, $actor, $page, $perPage);
    }

    public function sharedWithMePayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->queries->sharedWithMePayload($workspace, $actor, $page, $perPage);
    }

    public function recentPayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 25,
    ): array {
        return $this->queries->recentPayload($workspace, $actor, $page, $perPage);
    }

    public function activityPayload(
        Model $workspace,
        Model $actor,
        int $page = 1,
        int $perPage = 50,
    ): array {
        return $this->queries->activityPayload($workspace, $actor, $page, $perPage);
    }

    public function listMediaVersions(Model $workspace, Media $media, ?Model $actor = null): array
    {
        return $this->queries->mediaVersionsPayload($workspace, $media, $actor);
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

        $this->deletion->delete($media);

        Event::dispatch(new MediaPermanentlyDeleted($deletedId, $workspaceId, $path, $actor));
    }

    public function permanentlyDeleteFolder(Model $workspace, Folder $folder, ?Model $actor = null): void
    {
        $folder = $this->assertWorkspaceFolder($workspace, $folder, true);

        if ($actor) {
            $this->access->authorizeEdit($workspace, $folder, $actor);
        }

        $this->library->permanentlyDeleteFolder($folder);

        $this->logActivity(
            subject: $folder,
            type: 'storage.folder.deleted',
            description: 'Folder permanently deleted.',
            actor: $actor,
        );
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
            $this->deletion->delete($media);
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





    public function searchPayload(
        Model $workspace,
        string $query,
        ?Model $actor = null,
        int $page = 1,
        int $perPage = 50,
        string $sortBy = 'updated_at',
        string $sortDir = 'desc',
    ): array {
        return $this->queries->searchPayload($workspace, $query, $actor, $page, $perPage, $sortBy, $sortDir);
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






}
