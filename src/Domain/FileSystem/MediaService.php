<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\Media\CurrentMediaSelectionService;
use Tetranyble\Storage\Domain\Media\MediaDeletionService;
use Tetranyble\Storage\Domain\Media\MediaLibraryService;
use Tetranyble\Storage\Domain\Media\MediaPostProcessor;
use Tetranyble\Storage\Domain\Media\MediaVersioningService;
use Tetranyble\Storage\Enums\AccessScope;
use Tetranyble\Storage\Enums\MediaRevisionEventType;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\Media;
use Tetranyble\Storage\Support\StorageConfig;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class MediaService implements MediaUploader, RemoteMediaImporter
{
    public function __construct(
        protected FileSystemContract $files,
        protected StorageService $storage,
        protected StorageLifecycleService $lifecycle,
        protected StorageOrphanService $orphans,
        protected MediaLibraryService $library,
        protected MediaPostProcessor $postProcessor,
        protected ActivityLogger $activityLogger,
        protected MediaVersioningService $versioning,
        protected RemoteMediaDownloadService $remoteDownloader,
        protected MediaDeletionService $deletion,
        protected CurrentMediaSelectionService $currentSelection,
    ) {}

    public function uploadUploadedFile(UploadedFile $file, MediaUploadOptions $options): Media
    {
        return $this->persistUploadedFile($file, $options);
    }

    public function finalizeChunkedUpload(UploadedFile $file, MediaUploadOptions $options): Media
    {
        $chunkedOptions = new MediaUploadOptions(
            model: $options->model,
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $options->disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            visibility: $options->visibility,
            strategy: UploadStrategy::CHUNKED,
            module: $options->module,
            customProperties: $options->customProperties,
            dispatchPostProcessing: $options->dispatchPostProcessing,
            replaceExisting: $options->replaceExisting,
            makeCurrent: $options->makeCurrent,
            temporary: $options->temporary,
            expiresAt: $options->expiresAt,
            preserveFilename: $options->preserveFilename,
            originalName: $options->originalName,
            attribution: $options->attribution,
            intendedUsage: $options->intendedUsage,
            redirectTo: $options->redirectTo,
        );

        return $this->persistUploadedFile($file, $chunkedOptions);
    }

    public function uploadFor(
        Model $model,
        UploadedFile $file,
        string $description = '',
        string $attribution = '',
        string $directory = 'media',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        bool $makeCurrent = true,
    ): Media {
        $options = MediaUploadOptions::forModel(
            model: $model,
            purpose: $purpose,
            directory: $directory,
            disk: $disk,
            module: $directory,
            replaceExisting: $replaceExisting,
            makeCurrent: $makeCurrent,
            label: $description,
            attribution: $attribution,
        );

        return $this->uploadUploadedFile($file, $options);
    }

    public function attachPathFor(
        Model $model,
        string $path,
        string $description = '',
        string $attribution = '',
        string $directory = 'media',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        bool $preserveFilename = false,
        bool $makeCurrent = true,
    ): Media {
        $options = MediaUploadOptions::forModel(
            model: $model,
            purpose: $purpose,
            directory: $directory,
            disk: $disk,
            module: $directory,
            replaceExisting: $replaceExisting,
            makeCurrent: $makeCurrent,
            label: $description,
            attribution: $attribution,
        );

        $options = new MediaUploadOptions(
            model: $options->model,
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $options->disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            strategy: $options->strategy,
            module: $options->module,
            customProperties: $options->customProperties,
            replaceExisting: $options->replaceExisting,
            makeCurrent: $options->makeCurrent,
            preserveFilename: $preserveFilename,
            attribution: $options->attribution,
        );

        return $this->persistPathUpload($path, $options);
    }

    public function attachExternalFor(
        Model $model,
        string $url,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        ?string $description = null,
        ?string $attribution = null,
        bool $replaceExisting = true,
        bool $makeCurrent = true,
    ): Media {
        $disk ??= $this->guessDiskForExternalUrl($url);
        $workspace = $this->resolveWorkspaceFromModel($model);
        $replacedMedia = $this->findCurrentVersionForModel($model, $purpose);
        if (! $replaceExisting) {
            $replacedMedia = null;
        }
        [$versionGroupUuid, $versionNumber, $previousVersionId] = $this->versioning->prepareContext($replacedMedia, $makeCurrent);

        $folder = $workspace ? $this->resolveFolderForOptions($workspace, MediaUploadOptions::forModel($model, $purpose)) : null;

        $actor = $this->resolveActorById($replacedMedia?->uploaded_by);

        /** @var Media $media */
        $media = DB::transaction(function () use (
            $model,
            $description,
            $attribution,
            $url,
            $disk,
            $purpose,
            $workspace,
            $folder,
            $versionGroupUuid,
            $versionNumber,
            $previousVersionId,
            $makeCurrent,
        ): Media {
            if ($makeCurrent) {
                $this->currentSelection->clearOthers($model, $purpose);
            }

            /** @var Media $created */
            $created = $model->media()->create([
                'description' => $description,
                'attribution' => $attribution,
                'size' => null,
                'path' => $url,
                'mime_type' => null,
                'disk' => $disk,
                'use' => $purpose,
                'module' => null,
                'upload_strategy' => UploadStrategy::SINGLE,
                'custom_properties' => [],
                'workspace_id' => $workspace?->id,
                'folder_id' => $folder?->id,
                'access_scope' => $folder?->access_scope ?? AccessScope::default(),
                'is_temporary' => false,
                'temporary_expires_at' => null,
            ]);

            $this->versioning->applyContext(
                $created,
                [$versionGroupUuid, $versionNumber, $previousVersionId],
                $makeCurrent,
            );

            return $created;
        });

        $this->recordRevisionActivity(
            media: $media,
            eventType: $replacedMedia ? MediaRevisionEventType::REVISION_UPLOADED : MediaRevisionEventType::EXTERNAL_ATTACHED,
            actor: $actor,
            sourceMedia: $replacedMedia,
            supersededMedia: $replacedMedia,
            meta: [
                'version_group_uuid' => $media->version_group_uuid,
                'version_number' => $media->version_number,
                'origin' => 'external_url',
                'external_url' => $url,
            ],
        );

        return $media;
    }

    public function revisionsFor(Media $media)
    {
        return $this->versioning->versions($media);
    }

    public function revisionActivityFor(Media $media)
    {
        return $this->versioning->activity($media);
    }

    public function createRevisionFromUpload(Media $media, UploadedFile $file, ?int $userId = null): Media
    {
        $options = $this->versionUploadOptions($media, $userId);

        $options = new MediaUploadOptions(
            model: $options->model,
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $options->disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            visibility: $options->visibility,
            strategy: $options->strategy,
            module: $options->module,
            customProperties: $options->customProperties,
            dispatchPostProcessing: $options->dispatchPostProcessing,
            replaceExisting: false,
            temporary: false,
            expiresAt: null,
            preserveFilename: false,
            originalName: $file->getClientOriginalName(),
            attribution: $options->attribution,
            intendedUsage: $options->intendedUsage,
            redirectTo: $options->redirectTo,
            replacesMediaId: $this->versioning->currentVersion($media)?->id ?? $media->id,
            auditEventType: MediaRevisionEventType::REVISION_UPLOADED,
            auditSourceMediaId: $media->id,
            auditSupersededMediaId: $this->versioning->currentVersion($media)?->id ?? $media->id,
            auditMeta: ['origin' => 'uploaded_file'],
        );

        return $this->persistUploadedFile($file, $options);
    }

    public function restoreRevision(Media $revision, ?int $userId = null): Media
    {
        $revision = $revision->refresh();
        $current = $this->versioning->currentVersion($revision);

        if ($current && $current->id === $revision->id) {
            return $revision;
        }

        $options = $this->versionUploadOptions($revision, $userId);
        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $revision->disk instanceof Disk ? $revision->disk : $this->resolveDisk($options);

        if ($revision->path && ! $this->isExternalUrl($revision->path) && $disk instanceof Disk) {
            $targetDirectory = $this->resolveUploadDirectory($options, $workspace);
            $filename = $this->generateStoredFilename(
                $revision->original_name ?: basename((string) $revision->path),
                false
            );
            $storedPath = trim($targetDirectory.'/'.$filename, '/');

            try {
                if (! $this->files->copy($revision->path, $storedPath, $disk, $disk)) {
                    throw new \RuntimeException('Unable to restore media revision on storage.');
                }
            } catch (\Throwable $exception) {
                // Some adapters can leave a partial destination even when copy()
                // returns false or throws. The source revision remains untouched.
                $this->orphans->deleteOrTrack(
                    $disk,
                    $storedPath,
                    $workspace?->getKey() ? (int) $workspace->getKey() : null,
                    $revision->size ? (int) $revision->size : null,
                    'revision_restore_rollback',
                );
                throw $exception;
            }
        } else {
            $storedPath = $revision->path;
        }

        $restoreOptions = new MediaUploadOptions(
            model: $options->model,
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            visibility: $options->visibility,
            strategy: $options->strategy,
            module: $options->module,
            customProperties: $options->customProperties,
            dispatchPostProcessing: false,
            replaceExisting: false,
            temporary: false,
            expiresAt: null,
            preserveFilename: false,
            originalName: $revision->original_name,
            attribution: $revision->attribution,
            intendedUsage: $options->intendedUsage,
            redirectTo: $options->redirectTo,
            replacesMediaId: $current?->id ?? $revision->id,
            auditEventType: MediaRevisionEventType::REVISION_RESTORED,
            auditSourceMediaId: $revision->id,
            auditSupersededMediaId: $current?->id,
            auditMeta: ['origin' => 'restore'],
        );

        $restored = $this->persistExistingStoredFile(
            storedPath: $storedPath,
            size: (int) ($revision->size ?? 0),
            mime: $revision->mime_type,
            originalName: $revision->original_name,
            checksum: $revision->sha256,
            options: $restoreOptions,
            workspace: $workspace,
            disk: $disk,
        );

        if ($restored->previous_version_id !== $revision->id) {
            $restored->forceFill([
                'previous_version_id' => $revision->id,
            ])->save();
        }

        return $restored->refresh();
    }

    public function attachSourceFor(
        Model $model,
        UploadedFile|string $source,
        string $description = '',
        string $attribution = '',
        string $directory = 'media',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        bool $preserveFilenameForPath = false,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
        bool $makeCurrent = true,
    ): Media {
        if ($source instanceof UploadedFile) {
            return $this->uploadFor(
                $model,
                $source,
                $description,
                $attribution,
                $directory,
                $purpose,
                $disk,
                $replaceExisting,
                $makeCurrent,
            );
        }

        if ($this->isExternalUrl($source)) {
            return $this->attachDownloadedFromUrlFor(
                $model,
                $source,
                $description,
                $attribution,
                $directory,
                $purpose,
                $disk,
                $replaceExisting,
                $maxSizeBytes,
                $allowedMimes,
                $makeCurrent,
            );
        }

        return $this->attachPathFor(
            $model,
            $source,
            $description,
            $attribution,
            $directory,
            $purpose,
            $disk,
            $replaceExisting,
            $preserveFilenameForPath,
            $makeCurrent,
        );
    }

    protected function attachDownloadedFromUrlFor(
        Model $model,
        string $url,
        string $description = '',
        string $attribution = '',
        string $directory = 'media',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
        bool $makeCurrent = true,
    ): Media {
        $options = MediaUploadOptions::forModel(
            model: $model,
            purpose: $purpose,
            directory: $directory,
            disk: $disk,
            module: $directory,
            replaceExisting: $replaceExisting,
            makeCurrent: $makeCurrent,
            label: $description,
            attribution: $attribution,
        );

        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);

        [$storedPath, $size, $mime] = $this->remoteDownloader->download(
            $url,
            $targetDirectory,
            $disk,
            filename: null,
            workspace: $workspace,
            maxSizeBytes: $maxSizeBytes,
            allowedMimes: $allowedMimes,
        );

        return $this->persistExistingStoredFile(
            storedPath: $storedPath,
            size: $size,
            mime: $mime,
            originalName: basename(parse_url($url, PHP_URL_PATH) ?: $storedPath),
            checksum: null,
            options: $options,
            workspace: $workspace,
            disk: $disk,
        );
    }

    public function uploadStandalone(
        UploadedFile $file,
        string $description = '',
        string $attribution = '',
        string $directory = 'uploads',
        ?MediaPurpose $purpose = null,
        ?Disk $disk = null,
        ?int $workspaceId = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Media {
        $options = MediaUploadOptions::forStandalone(
            workspaceId: $workspaceId,
            purpose: $purpose ?? MediaPurpose::GENERAL,
            directory: $directory,
            disk: $disk,
            temporary: true,
            expiresAt: $expiresAt ?: now()->addWeek(),
            label: $description,
            attribution: $attribution,
            module: $directory,
        );

        return $this->uploadUploadedFile($file, $options);
    }

    public function uploadStandaloneFromPath(
        string $path,
        string $description = '',
        string $attribution = '',
        string $directory = 'uploads',
        ?MediaPurpose $purpose = null,
        ?Disk $disk = null,
        ?int $workspaceId = null,
        bool $preserveFilename = false,
        ?\DateTimeInterface $expiresAt = null,
    ): Media {
        $options = MediaUploadOptions::forStandalone(
            workspaceId: $workspaceId,
            purpose: $purpose ?? MediaPurpose::GENERAL,
            directory: $directory,
            disk: $disk,
            temporary: true,
            expiresAt: $expiresAt ?: now()->addWeek(),
            label: $description,
            attribution: $attribution,
            module: $directory,
        );

        $options = new MediaUploadOptions(
            workspaceId: $options->workspaceId,
            userId: $options->userId,
            folderId: $options->folderId,
            disk: $options->disk,
            directory: $options->directory,
            purpose: $options->purpose,
            label: $options->label,
            title: $options->title,
            strategy: $options->strategy,
            module: $options->module,
            customProperties: $options->customProperties,
            temporary: $options->temporary,
            expiresAt: $options->expiresAt,
            preserveFilename: $preserveFilename,
            attribution: $options->attribution,
        );

        return $this->persistPathUpload($path, $options);
    }

    public function uploadStandaloneFromUrl(
        string $url,
        ?MediaPurpose $purpose = null,
        ?Disk $disk = null,
        ?string $description = null,
        ?string $attribution = null,
        string $directory = 'uploads',
        ?int $workspaceId = null,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
        ?\DateTimeInterface $expiresAt = null,
    ): Media {
        $options = MediaUploadOptions::forStandalone(
            workspaceId: $workspaceId,
            purpose: $purpose ?? MediaPurpose::GENERAL,
            directory: $directory,
            disk: $disk,
            temporary: true,
            expiresAt: $expiresAt ?: now()->addWeek(),
            label: $description,
            attribution: $attribution,
            module: $directory,
        );

        return $this->uploadFromUrl($url, $options, $maxSizeBytes, $allowedMimes);
    }

    public function uploadFromUrl(
        string $url,
        MediaUploadOptions $options,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
    ): Media {

        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);

        [$storedPath, $size, $mime] = $this->remoteDownloader->download(
            $url,
            $targetDirectory,
            $disk,
            filename: null,
            workspace: $workspace,
            maxSizeBytes: $maxSizeBytes,
            allowedMimes: $allowedMimes,
        );

        return $this->persistExistingStoredFile(
            storedPath: $storedPath,
            size: $size,
            mime: $mime,
            originalName: basename(parse_url($url, PHP_URL_PATH) ?: $storedPath),
            checksum: null,
            options: $options,
            workspace: $workspace,
            disk: $disk,
        );
    }

    public function attachExistingMediaToModel(
        Media $media,
        Model $model,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        bool $replaceExisting = false,
        string $directory = 'media',
        bool $makeCurrent = true,
    ): Media {
        $options = MediaUploadOptions::forModel(
            model: $model,
            purpose: $purpose,
            directory: $directory,
            module: $directory,
            replaceExisting: $replaceExisting,
            makeCurrent: $makeCurrent,
        );

        $workspace = $this->resolveWorkspaceFromOptions($options) ?? StorageConfig::findWorkspace($media->workspace_id);
        $sourceWorkspace = $media->workspace_id ? StorageConfig::findWorkspace($media->workspace_id) : null;
        $size = (int) ($media->size ?? 0);
        $hasStoredObject = is_string($media->path)
            && $media->path !== ''
            && ! $this->isExternalUrl($media->path)
            && $media->disk instanceof Disk;
        $workspaceChanged = $hasStoredObject
            && $workspace
            && (int) ($media->workspace_id ?? 0) !== (int) $workspace->getKey();
        $destinationReserved = false;
        $copiedPath = null;
        $oldPath = $media->path;
        $disk = $media->disk instanceof Disk ? $media->disk : null;

        try {
            // Reserve destination quota before touching the old workspace. On any
            // failure the reservation is released and source accounting remains.
            if ($workspaceChanged && $size > 0) {
                $this->storage->increaseUsage($workspace, $size);
                $destinationReserved = true;
            }

            $newPath = $oldPath;
            if ($hasStoredObject && $disk) {
                $newPath = trim($this->resolveUploadDirectory($options, $workspace).'/'.basename((string) $oldPath), '/');
                if ($newPath !== $oldPath) {
                    // Copy first instead of move. The original remains a valid
                    // rollback source until the DB transaction commits.
                    if (! $this->files->copy((string) $oldPath, $newPath, $disk, $disk)) {
                        $this->orphans->deleteOrTrack(
                            $disk,
                            $newPath,
                            $workspace?->getKey() ? (int) $workspace->getKey() : null,
                            $size > 0 ? $size : null,
                            'attach_rollback',
                        );
                        throw new \RuntimeException('Unable to copy media into its attached storage location.');
                    }
                    $copiedPath = $newPath;
                }
            }

            $replacedMedia = $replaceExisting
                ? $this->findCurrentVersionForModel($model, $purpose, $media->id)
                : null;
            $versionContext = $this->versioning->prepareContext($replacedMedia, $makeCurrent);
            $folder = $workspace ? $this->resolveFolderForOptions($workspace, $options) : null;

            DB::transaction(function () use (
                $media,
                $model,
                $workspace,
                $sourceWorkspace,
                $folder,
                $purpose,
                $options,
                $replacedMedia,
                $versionContext,
                $makeCurrent,
                $newPath,
                $workspaceChanged,
                $size,
            ): void {
                if ($makeCurrent) {
                    $this->currentSelection->clearOthers($model, $purpose, $media->getKey());
                }

                $media->forceFill([
                    'workspace_id' => $workspace?->id,
                    'folder_id' => $folder?->id,
                    'mediable_id' => $model->getKey(),
                    'mediable_type' => $model->getMorphClass(),
                    'use' => $purpose,
                    'module' => $options->module ?: $media->module,
                    'upload_strategy' => $options->strategy !== UploadStrategy::SINGLE ? $options->strategy : ($media->upload_strategy ?? UploadStrategy::SINGLE),
                    'path' => $newPath,
                    'is_temporary' => false,
                    'temporary_expires_at' => null,
                ])->save();

                if ($replacedMedia) {
                    $this->versioning->applyContext($media, $versionContext, $makeCurrent);
                } else {
                    $this->versioning->ensureVersionSeed($media);
                    $media->forceFill(['current' => $makeCurrent])->save();
                }

                if ($workspaceChanged && $sourceWorkspace && $size > 0) {
                    $this->storage->decreaseUsage($sourceWorkspace, $size);
                }
            });
        } catch (\Throwable $exception) {
            if ($copiedPath && $disk) {
                $this->orphans->deleteOrTrack(
                    $disk,
                    $copiedPath,
                    $workspace?->getKey() ? (int) $workspace->getKey() : null,
                    $size > 0 ? $size : null,
                    'attach_rollback',
                );
            }

            if ($destinationReserved && $workspace) {
                $this->storage->decreaseUsage($workspace, $size);
            }
            if ($sourceWorkspace) {
                $sourceWorkspace->refresh();
            }

            throw $exception;
        }

        // The DB now points at the copy. Failure to remove the old object is an
        // orphan-cleanup concern, not a reason to roll back a committed attach.
        if ($copiedPath && $disk && is_string($oldPath) && $oldPath !== '') {
            $this->orphans->deleteOrTrack(
                $disk,
                $oldPath,
                $sourceWorkspace?->getKey() ? (int) $sourceWorkspace->getKey() : null,
                $size > 0 ? $size : null,
                'relocation_cleanup',
            );
        }

        $this->recordRevisionActivity(
            media: $media,
            eventType: $replacedMedia ? MediaRevisionEventType::ATTACHED_EXISTING : MediaRevisionEventType::CREATED,
            actor: $this->resolveActorById($options->userId),
            sourceMedia: $media->previous_version_id ? Media::find($media->previous_version_id) : null,
            supersededMedia: $replacedMedia,
            meta: [
                'version_group_uuid' => $media->version_group_uuid,
                'version_number' => $media->version_number,
                'origin' => 'attach_existing',
            ],
        );

        return $media->refresh();
    }

    public function clearMedia(Model $model): void
    {
        if (! method_exists($model, 'media')) {
            return;
        }

        $model->media()->delete();
    }

    public function purgeMedia(Model $model): void
    {
        if (! method_exists($model, 'media')) {
            return;
        }

        foreach ($model->media()->get() as $media) {
            $this->deleteMediaItem($media);
        }
    }

    public function disableMedia(Model $model): void
    {
        if (! method_exists($model, 'media')) {
            return;
        }

        $model->media()->update(['current' => false]);
    }

    public function deleteMediaItem(Media $media): void
    {
        $this->deletion->delete($media);
    }

    protected function persistUploadedFile(UploadedFile $file, MediaUploadOptions $options): Media
    {
        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $size = (int) ($file->getSize() ?? 0);
        $mime = $file->getClientMimeType() ?: $file->getMimeType();
        $originalName = $options->originalName ?? $file->getClientOriginalName();
        $checksum = $this->checksumForPath($file->getRealPath());
        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);
        $storedFilename = $this->generateStoredFilename($originalName, $options->preserveFilename);

        $state = $this->lifecycle->storeAndCommit(
            workspace: $workspace,
            disk: $disk,
            size: $size,
            store: fn (): string => $this->files->disk($disk)->storeAs(
                $file,
                $storedFilename,
                $targetDirectory,
            ),
            commit: fn (string $storedPath): array => $this->persistStoredFileRecord(
                storedPath: $storedPath,
                size: $size,
                mime: $mime,
                originalName: $originalName,
                checksum: $checksum,
                options: $options,
                workspace: $workspace,
                disk: $disk,
            ),
            expectedPath: trim($targetDirectory.'/'.$storedFilename, '/'),
        );

        return $this->finishStoredFilePersistence($state, $options);
    }

    protected function persistPathUpload(string $path, MediaUploadOptions $options): Media
    {
        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);
        $originalName = basename($path);
        $storedFilename = $this->generateStoredFilename($originalName, $options->preserveFilename);
        $expectedPath = trim($targetDirectory.'/'.$storedFilename, '/');
        $storedPath = null;
        $handedToLifecycle = false;
        $checksum = $this->checksumForPath($path);

        try {
            $storedPath = $this->files->disk($disk)->storeAs(
                $path,
                $storedFilename,
                $targetDirectory
            );

            // Size/MIME inspection can itself fail after the object has already
            // been written. Keep this inside the compensation boundary.
            $size = $this->files->size($storedPath, $disk);
            $mime = $this->files->mimeType($storedPath, $disk);

            $handedToLifecycle = true;

            return $this->persistExistingStoredFile(
                storedPath: $storedPath,
                size: $size,
                mime: $mime,
                originalName: $originalName,
                checksum: $checksum,
                options: $options,
                workspace: $workspace,
                disk: $disk,
            );
        } catch (\Throwable $exception) {
            // persistExistingStoredFile() owns compensation after hand-off. Before
            // that point, this method must clean a failed/partial path write.
            if (! $handedToLifecycle) {
                $rollbackPath = is_string($storedPath) && $storedPath !== '' ? $storedPath : $expectedPath;
                $this->orphans->deleteOrTrack(
                    $disk,
                    $rollbackPath,
                    $workspace?->getKey() ? (int) $workspace->getKey() : null,
                    reason: 'path_upload_rollback',
                );
            }

            throw $exception;
        }
    }

    protected function persistExistingStoredFile(
        string $storedPath,
        int|float|null $size,
        ?string $mime,
        ?string $originalName,
        ?string $checksum,
        MediaUploadOptions $options,
        ?Model $workspace,
        Disk $disk,
    ): Media {
        $normalizedSize = max(0, (int) ($size ?? 0));

        // External URLs have no package-owned physical object and must never be
        // entered into object cleanup/quota compensation.
        if ($this->isExternalUrl($storedPath)) {
            $state = $this->persistStoredFileRecord(
                storedPath: $storedPath,
                size: $size,
                mime: $mime,
                originalName: $originalName,
                checksum: $checksum,
                options: $options,
                workspace: $workspace,
                disk: $disk,
            );
        } else {
            $state = $this->lifecycle->commitExisting(
                workspace: $workspace,
                disk: $disk,
                storedPath: $storedPath,
                size: $normalizedSize,
                commit: fn (): array => $this->persistStoredFileRecord(
                    storedPath: $storedPath,
                    size: $size,
                    mime: $mime,
                    originalName: $originalName,
                    checksum: $checksum,
                    options: $options,
                    workspace: $workspace,
                    disk: $disk,
                ),
            );
        }

        return $this->finishStoredFilePersistence($state, $options);
    }

    /**
     * Persist only the authoritative database record/version state.
     *
     * This method intentionally performs no activity logging or post-processing;
     * StorageLifecycleService relies on the callback boundary to know whether it
     * is still safe to compensate the physical object and quota reservation.
     *
     * @return array{media: Media, replaced_media: Media|null, version_context: array}
     */
    protected function persistStoredFileRecord(
        string $storedPath,
        int|float|null $size,
        ?string $mime,
        ?string $originalName,
        ?string $checksum,
        MediaUploadOptions $options,
        ?Model $workspace,
        Disk $disk,
    ): array {
        $replacedMedia = null;
        $versionContext = [];

        $media = DB::transaction(function () use (
            $storedPath,
            $size,
            $mime,
            $originalName,
            $checksum,
            $options,
            $workspace,
            $disk,
            &$replacedMedia,
            &$versionContext,
        ): Media {
            $folder = $workspace ? $this->resolveFolderForOptions($workspace, $options) : null;
            $attributes = [
                'description' => $options->description(),
                'attribution' => $options->attribution,
                'size' => $size,
                'path' => $storedPath,
                'original_name' => $originalName,
                'mime_type' => $mime,
                'disk' => $disk,
                'use' => $options->purpose,
                'module' => $options->module,
                'upload_strategy' => $options->strategy,
                'custom_properties' => $options->customProperties,
                'sha256' => $checksum,
                'workspace_id' => $workspace?->id,
                'folder_id' => $folder?->id,
                'uploaded_by' => $options->userId,
                'uploaded_at' => now(),
                'is_temporary' => $options->temporary,
                'temporary_expires_at' => $options->temporary ? ($options->expiresAt ?: now()->addWeek()) : null,
            ];

            $replacedMedia = $this->resolveReplacedMedia($options);
            $versionContext = $this->versioning->prepareContext($replacedMedia, $options->makeCurrent);
            if ($options->model && $options->makeCurrent) {
                $this->currentSelection->clearOthers($options->model, $options->purpose);
            }
            $created = $this->createMediaRecord($options->model, $attributes);
            $this->versioning->applyContext($created, $versionContext, $options->makeCurrent);

            return $created;
        });

        return [
            'media' => $media,
            'replaced_media' => $replacedMedia,
            'version_context' => $versionContext,
        ];
    }

    /**
     * Post-commit side effects. A failure here must not trigger storage/quota
     * compensation because the Media row is already the authoritative owner of
     * the object.
     *
     * @param array{media: Media, replaced_media: Media|null, version_context: array} $state
     */
    protected function finishStoredFilePersistence(array $state, MediaUploadOptions $options): Media
    {
        /** @var Media $media */
        $media = $state['media'];
        /** @var Media|null $replacedMedia */
        $replacedMedia = $state['replaced_media'];

        $this->recordRevisionActivity(
            media: $media,
            eventType: $options->auditEventType
                ?? ($replacedMedia ? MediaRevisionEventType::REVISION_UPLOADED : MediaRevisionEventType::CREATED),
            actor: $this->resolveActorById($options->userId),
            sourceMedia: $this->resolveAuditMedia($options->auditSourceMediaId, $replacedMedia),
            supersededMedia: $this->resolveAuditMedia($options->auditSupersededMediaId, $replacedMedia),
            meta: array_merge([
                'version_group_uuid' => $media->version_group_uuid,
                'version_number' => $media->version_number,
                'previous_version_id' => $media->previous_version_id,
                'origin' => 'stored_file',
                'upload_strategy' => $media->upload_strategy?->value ?? null,
            ], $options->auditMeta),
            changes: [
                'before' => $replacedMedia ? $this->revisionSnapshot($replacedMedia) : [],
                'after' => $this->revisionSnapshot($media),
            ],
        );

        if ($options->dispatchPostProcessing) {
            $this->postProcessor->dispatch($media, $options);
        }

        return $media;
    }

    protected function createMediaRecord(?Model $model, array $attributes): Media
    {
        if ($model && method_exists($model, 'media')) {
            /** @var Media $media */
            $media = $model->media()->create($attributes);

            return $media;
        }

        /** @var Media $media */
        $media = Media::create($attributes);

        return $media;
    }

    public function setCurrentMedia(Media $media): Media
    {
        return $this->currentSelection->select($media);
    }



    protected function resolveReplacedMedia(MediaUploadOptions $options): ?Media
    {
        if ($options->replacesMediaId) {
            return Media::query()->find($options->replacesMediaId);
        }

        if ($options->model && $options->replaceExisting) {
            return $this->findCurrentVersionForModel($options->model, $options->purpose);
        }

        return null;
    }

    protected function findCurrentVersionForModel(Model $model, MediaPurpose $purpose, ?int $ignoreMediaId = null): ?Media
    {
        if (! method_exists($model, 'media')) {
            return null;
        }

        $query = $model->media()
            ->where('use', $purpose)
            ->where('current', true)
            ->latest('id');

        if ($ignoreMediaId) {
            $query->where('id', '!=', $ignoreMediaId);
        }

        return $query->first();
    }

    protected function versionUploadOptions(Media $media, ?int $userId = null): MediaUploadOptions
    {
        $workspace = StorageConfig::findWorkspace($media->workspace_id);

        if ($media->mediable_type && $media->mediable_id && class_exists($media->mediable_type)) {
            $model = $media->mediable_type::query()->find($media->mediable_id);
            if ($model instanceof Model) {
                return MediaUploadOptions::forModel(
                    model: $model,
                    purpose: $media->use ?? MediaPurpose::GENERAL,
                    directory: $media->module ?: 'media',
                    disk: $media->disk instanceof Disk ? $media->disk : null,
                    userId: $userId,
                    module: $media->module,
                    customProperties: $media->custom_properties ?? [],
                    label: $media->description,
                    attribution: $media->attribution,
                    folderId: $media->folder_id,
                );
            }
        }

        return new MediaUploadOptions(
            workspaceId: $workspace?->id,
            userId: $userId,
            folderId: $media->folder_id,
            disk: $media->disk instanceof Disk ? $media->disk : null,
            directory: $media->module ?: 'media',
            purpose: $media->use ?? MediaPurpose::GENERAL,
            label: $media->description,
            module: $media->module ?: 'media',
            customProperties: $media->custom_properties ?? [],
            temporary: false,
            attribution: $media->attribution,
        );
    }

    protected function resolveActorById(?int $userId): ?Model
    {
        return StorageConfig::findUser($userId);
    }

    protected function resolveAuditMedia(?int $mediaId, ?Media $fallback = null): ?Media
    {
        if ($mediaId) {
            return Media::find($mediaId);
        }

        return $fallback;
    }

    protected function revisionSnapshot(Media $media): array
    {
        return [
            'id' => $media->id,
            'current' => (bool) $media->current,
            'version_number' => (int) ($media->version_number ?? 1),
            'version_group_uuid' => $media->version_group_uuid,
            'previous_version_id' => $media->previous_version_id,
            'path' => $media->path,
            'original_name' => $media->original_name,
            'uploaded_by' => $media->uploaded_by,
            'uploaded_at' => optional($media->uploaded_at)?->toIso8601String(),
        ];
    }

    protected function recordRevisionActivity(
        Media $media,
        MediaRevisionEventType $eventType,
        ?Model $actor = null,
        ?Media $sourceMedia = null,
        ?Media $supersededMedia = null,
        array $meta = [],
        array $changes = [],
    ): void {
        $description = match ($eventType) {
            MediaRevisionEventType::CREATED => 'Media created.',
            MediaRevisionEventType::REVISION_UPLOADED => 'Media revision uploaded.',
            MediaRevisionEventType::REVISION_RESTORED => 'Media revision restored.',
            MediaRevisionEventType::ATTACHED_EXISTING => 'Existing media attached as current version.',
            MediaRevisionEventType::EXTERNAL_ATTACHED => 'External media attached.',
        };

        $this->activityLogger->log(
            subject: $media,
            type: 'storage.media.'.$eventType->value,
            description: $description,
            actor: $actor,
            meta: array_merge([
                'event_type' => $eventType,
                'version_group_uuid' => $media->version_group_uuid,
                'version_number' => $media->version_number,
                'source_media_id' => $sourceMedia?->id,
                'superseded_media_id' => $supersededMedia?->id,
            ], $meta),
            changes: $changes,
            workspaceId: $media->workspace_id,
        );
    }

    protected function resolveWorkspaceFromOptions(MediaUploadOptions $options): ?Model
    {
        if ($options->model) {
            return $this->resolveWorkspaceFromModel($options->model);
        }

        if ($options->workspaceId) {
            return StorageConfig::findWorkspace($options->workspaceId);
        }

        return null;
    }

    protected function resolveWorkspaceFromModel(Model $model): ?Model
    {
        return StorageConfig::resolveWorkspaceFromModel($model);
    }

    protected function resolveDisk(MediaUploadOptions $options, ?string $externalUrl = null): Disk
    {
        if ($options->disk) {
            return $options->disk;
        }

        if ($externalUrl) {
            return $this->guessDiskForExternalUrl($externalUrl);
        }

        return match (true) {
            in_array($options->module, ['payslip', 'statement', 'file-centre'], true) => Disk::PRIVATE,
            in_array($options->purpose, [
                MediaPurpose::IMPORT_SOURCE,
                MediaPurpose::BANK_STATEMENT,
                MediaPurpose::LOAN_SUPPORTING_DOCUMENT,
                MediaPurpose::IDENTITY_DOCUMENT_FRONT,
                MediaPurpose::IDENTITY_DOCUMENT_BACK,
                MediaPurpose::BOARD_RESOLUTION,
                MediaPurpose::BUSINESS_LICENSE,
                MediaPurpose::MEMORANDUM_ARTICLES,
                MediaPurpose::NEXT_OF_KIN_ID,
            ], true) => Disk::PRIVATE,
            default => $this->files->getDefaultDisk(),
        };
    }

    protected function resolveUploadDirectory(MediaUploadOptions $options, ?Model $workspace): string
    {
        $workspaceSegment = $workspace
            ? 'workspaces/'.($workspace->uuid ?: $workspace->id)
            : 'global';
        $moduleSegment = trim($options->module ?: ($options->directory ?: 'media'), '/');
        $moduleSegment = $moduleSegment !== '' ? $moduleSegment : 'media';
        $dateSegment = now()->format('Y/m/d');

        $segments = [$workspaceSegment, $moduleSegment, $dateSegment];

        if ($options->folderId && $workspace) {
            $folder = $this->resolveWorkspaceFolder($workspace, $options->folderId);
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

    protected function resolveFolderForOptions(Model $workspace, MediaUploadOptions $options): ?Folder
    {
        if ($options->folderId) {
            return $this->resolveWorkspaceFolder($workspace, $options->folderId);
        }

        if ($options->module === 'file-centre') {
            return $this->library->resolveOrCreateFolderPath($workspace, '');
        }

        if ($options->model) {
            return $this->library->resolveOrCreateFolderPath(
                $workspace,
                $this->resolveFolderPathForModel($options->model, $options->purpose)
            );
        }

        return $this->library->resolveOrCreateFolderPath(
            $workspace,
            $this->resolveFolderPathForStandalone($options)
        );
    }

    protected function resolveWorkspaceFolder(Model $workspace, int $folderId): Folder
    {
        return Folder::query()
            ->where('workspace_id', $workspace->id)
            ->findOrFail($folderId);
    }

    protected function resolveFolderPathForModel(Model $model, MediaPurpose $purpose): string
    {
        $base = method_exists($model, 'mediaBaseDirectory')
            ? $model->mediaBaseDirectory()
            : Str::kebab(class_basename($model)).'s';

        return trim("{$base}/{$model->getKey()}/".Str::slug($purpose->value), '/');
    }

    protected function resolveFolderPathForStandalone(MediaUploadOptions $options): string
    {
        $module = trim($options->module ?: 'free', '/');

        return trim($module.'/'.Str::slug($options->purpose->value), '/');
    }

    protected function generateStoredFilename(string $originalName, bool $preserveFilename = false): string
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

    protected function checksumForPath(?string $path): ?string
    {
        if (! is_string($path) || $path === '' || ! is_file($path)) {
            return null;
        }

        $hash = @hash_file('sha256', $path);

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    protected function guessDiskForExternalUrl(string $url): Disk
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

    protected function isExternalUrl(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (str_starts_with($path, '//')) {
            return true;
        }

        return filter_var($path, FILTER_VALIDATE_URL) !== false;
    }


}
