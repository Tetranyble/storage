<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Tetranyble\Storage\Contracts\RemoteMediaImporter;
use Tetranyble\Storage\Contracts\RemoteUrlValidator;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\RemoteDownloadException;
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
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;

class MediaService implements MediaUploader, RemoteMediaImporter
{
    public function __construct(
        protected FileSystemContract $files,
        protected StorageService $storage,
        protected MediaLibraryService $library,
        protected MediaPostProcessor $postProcessor,
        protected ActivityLogger $activityLogger,
        protected MediaVersioningService $versioning,
        protected RemoteUrlValidator $remoteUrlValidator,
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
                $this->lockAndClearCurrentMedia($model, $purpose);
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

            $copied = $this->files->copy($revision->path, $storedPath, $disk, $disk);
            if (! $copied) {
                throw new \RuntimeException('Unable to restore media revision on storage.');
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

        [$storedPath, $size, $mime] = $this->downloadRemoteToDisk(
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

        [$storedPath, $size, $mime] = $this->downloadRemoteToDisk(
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
        $size = (int) ($media->size ?? 0);

        if ($workspace) {
            if ($media->workspace_id === null && $size > 0) {
                $this->storage->assertCanStore($workspace, $size);
                $this->storage->increaseUsage($workspace, $size);
            } elseif ($media->workspace_id !== null && $media->workspace_id !== $workspace->id && $size > 0) {
                if ($oldWorkspace = StorageConfig::findWorkspace($media->workspace_id)) {
                    $this->storage->decreaseUsage($oldWorkspace, $size);
                }

                $this->storage->assertCanStore($workspace, $size);
                $this->storage->increaseUsage($workspace, $size);
            }
        }

        $replacedMedia = $replaceExisting
            ? $this->findCurrentVersionForModel($model, $purpose, $media->id)
            : null;
        $versionContext = $this->versioning->prepareContext($replacedMedia, $makeCurrent);

        if ($media->path && ! $this->isExternalUrl($media->path) && $media->disk instanceof Disk) {
            $newPath = trim($this->resolveUploadDirectory($options, $workspace).'/'.basename($media->path), '/');
            if ($newPath !== $media->path) {
                $this->files->disk($media->disk)->move($media->path, $newPath, $media->disk);
                $media->path = $newPath;
            }
        }

        $folder = $workspace ? $this->resolveFolderForOptions($workspace, $options) : null;

        DB::transaction(function () use (
            $media,
            $model,
            $workspace,
            $folder,
            $purpose,
            $options,
            $replacedMedia,
            $versionContext,
            $makeCurrent,
        ): void {
            if ($makeCurrent) {
                $this->lockAndClearCurrentMedia($model, $purpose, $media->getKey());
            }

            $media->forceFill([
                'workspace_id' => $workspace?->id,
                'folder_id' => $folder?->id,
                'mediable_id' => $model->getKey(),
                'mediable_type' => get_class($model),
                'use' => $purpose,
                'module' => $options->module ?: $media->module,
                'upload_strategy' => $options->strategy !== UploadStrategy::SINGLE ? $options->strategy : ($media->upload_strategy ?? UploadStrategy::SINGLE),
                'is_temporary' => false,
                'temporary_expires_at' => null,
            ])->save();

            if ($replacedMedia) {
                $this->versioning->applyContext($media, $versionContext, $makeCurrent);
            } else {
                $this->versioning->ensureVersionSeed($media);
                $media->forceFill(['current' => $makeCurrent])->save();
            }
        });

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

        return $media;
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
        $path = $media->path;

        $media->shares()->delete();
        $media->collaborators()->delete();

        if ($path && ! $this->isExternalUrl($path) && $media->disk instanceof Disk) {
            $this->files->disk($media->disk)->delete($path);
        }

        if ($media->workspace_id && $media->size) {
            if ($workspace = StorageConfig::findWorkspace($media->workspace_id)) {
                $this->storage->decreaseUsage($workspace, (int) $media->size);
            }
        }

        if (method_exists($media, 'forceDelete')) {
            $media->forceDelete();
        } else {
            $media->delete();
        }
    }

    protected function persistUploadedFile(UploadedFile $file, MediaUploadOptions $options): Media
    {
        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $size = (int) ($file->getSize() ?? 0);

        if ($workspace && $size > 0) {
            $this->storage->assertCanStore($workspace, $size);
        }

        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);
        $storedPath = $this->files->disk($disk)->storeAs(
            $file,
            $this->generateStoredFilename($options->originalName ?? $file->getClientOriginalName(), $options->preserveFilename),
            $targetDirectory
        );

        if ($workspace && $size > 0) {
            $this->storage->increaseUsage($workspace, $size);
        }

        return $this->persistExistingStoredFile(
            storedPath: $storedPath,
            size: $size,
            mime: $file->getClientMimeType() ?: $file->getMimeType(),
            originalName: $options->originalName ?? $file->getClientOriginalName(),
            checksum: $this->checksumForPath($file->getRealPath()),
            options: $options,
            workspace: $workspace,
            disk: $disk,
        );
    }

    protected function persistPathUpload(string $path, MediaUploadOptions $options): Media
    {
        $workspace = $this->resolveWorkspaceFromOptions($options);
        $disk = $this->resolveDisk($options);
        $targetDirectory = $this->resolveUploadDirectory($options, $workspace);
        $originalName = basename($path);
        $storedPath = $this->files->disk($disk)->storeAs(
            $path,
            $this->generateStoredFilename($originalName, $options->preserveFilename),
            $targetDirectory
        );

        $size = $this->files->size($storedPath, $disk);
        if ($workspace && $size > 0) {
            $this->storage->assertCanStore($workspace, $size);
            $this->storage->increaseUsage($workspace, $size);
        }

        return $this->persistExistingStoredFile(
            storedPath: $storedPath,
            size: $size,
            mime: $this->files->mimeType($storedPath, $disk),
            originalName: $originalName,
            checksum: $this->checksumForPath($path),
            options: $options,
            workspace: $workspace,
            disk: $disk,
        );
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

        $replacedMedia = null;
        $versionContext = [];

        $media = DB::transaction(function () use (
            $options,
            $attributes,
            &$replacedMedia,
            &$versionContext,
        ): Media {
            $replacedMedia = $this->resolveReplacedMedia($options);
            $versionContext = $this->versioning->prepareContext($replacedMedia, $options->makeCurrent);
            if ($options->model && $options->makeCurrent) {
                $this->lockAndClearCurrentMedia($options->model, $options->purpose);
            }
            $created = $this->createMediaRecord($options->model, $attributes);
            $this->versioning->applyContext($created, $versionContext, $options->makeCurrent);

            return $created;
        });

        [$versionGroupUuid, $versionNumber, $previousVersionId] = $versionContext;

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
        $model = $media->mediable;
        if (! $model instanceof Model) {
            throw new \RuntimeException('Standalone media cannot be selected as a model default.');
        }

        return DB::transaction(function () use ($model, $media): Media {
            $this->lockAndClearCurrentMedia($model, $media->use, $media->getKey());
            $media->forceFill(['current' => true])->save();

            return $media->refresh();
        });
    }

    protected function lockAndClearCurrentMedia(
        Model $model,
        MediaPurpose $purpose,
        int|string|null $exceptMediaId = null,
    ): void {
        $query = $model->media()
            ->where('use', $purpose)
            ->where('current', true);

        if ($exceptMediaId !== null) {
            $query->whereKeyNot($exceptMediaId);
        }

        $ids = $query->lockForUpdate()->pluck($query->getModel()->getQualifiedKeyName());
        if ($ids->isNotEmpty()) {
            $model->media()->whereKey($ids)->update(['current' => false]);
        }
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

    protected function downloadRemoteToDisk(
        string $url,
        string $directory,
        Disk $disk,
        ?string $filename = null,
        ?Model $workspace = null,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
    ): array {
        $maxSize = $maxSizeBytes ?? (int) config('tetranyble-storage.remote.max_size', 50 * 1024 * 1024);
        $allowed = $allowedMimes ?? config('tetranyble-storage.remote.allowed_mimes', []);
        $enforceMimes = is_array($allowed) && count($allowed) > 0;

        $response = null;
        $resolvedUrl = $url;
        $maxRedirects = max(0, (int) config('tetranyble-storage.remote.max_redirects', 3));

        for ($redirects = 0; $redirects <= $maxRedirects; $redirects++) {
            $this->remoteUrlValidator->assertSafe($resolvedUrl);
            $response = Http::timeout(60)
                ->withOptions(['stream' => true, 'allow_redirects' => false])
                ->get($resolvedUrl);

            if (! in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                break;
            }

            $location = $response->header('Location');
            if (! is_string($location) || $location === '' || $redirects === $maxRedirects) {
                throw new RemoteDownloadException('Remote URL exceeded the redirect limit.', $resolvedUrl, $response->status());
            }

            $resolvedUrl = (string) UriResolver::resolve(new Uri($resolvedUrl), new Uri($location));
        }

        if ($response === null) {
            throw new RemoteDownloadException('Remote URL did not return a response.', $resolvedUrl);
        }

        $response->throw();

        $status = $response->status();
        $contentLengthHeader = $response->header('Content-Length');
        if ($contentLengthHeader !== null) {
            $lengthBytes = (int) $contentLengthHeader;

            if ($maxSize > 0 && $lengthBytes > $maxSize) {
                throw new RemoteDownloadException(
                    message: sprintf('Remote file too large (%d bytes, max %d bytes).', $lengthBytes, $maxSize),
                    url: $url,
                    status: $status,
                    size: $lengthBytes,
                    mime: null,
                );
            }
        }

        $contentType = $response->header('Content-Type');
        $headerMime = $contentType ? strtolower(Str::before($contentType, ';')) : null;

        $pathPart = parse_url($resolvedUrl, PHP_URL_PATH) ?? '';
        $ext = pathinfo($pathPart, PATHINFO_EXTENSION);
        $body = $response->toPsrResponse()->getBody();
        $resource = fopen('php://temp/maxmemory:5242880', 'w+b');
        if (! is_resource($resource)) {
            throw new RemoteDownloadException('Unable to allocate a remote download stream.', $resolvedUrl, $status);
        }

        $size = 0;
        $sample = '';
        try {
            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    break;
                }

                $size += strlen($chunk);
                if ($maxSize > 0 && $size > $maxSize) {
                    throw new RemoteDownloadException(
                        sprintf('Downloaded file exceeds max size (%d bytes, max %d bytes).', $size, $maxSize),
                        $resolvedUrl,
                        $status,
                        $size,
                        $headerMime,
                    );
                }

                if (strlen($sample) < 16384) {
                    $sample .= substr($chunk, 0, 16384 - strlen($sample));
                }
                fwrite($resource, $chunk);
            }

            $detectedMime = $sample !== '' ? (new \finfo(FILEINFO_MIME_TYPE))->buffer($sample) : null;
            $mime = is_string($detectedMime) && $detectedMime !== 'application/octet-stream'
                ? strtolower($detectedMime)
                : $headerMime;

            if ($enforceMimes && (! $mime || ! in_array($mime, $allowed, true))) {
                throw new RemoteDownloadException(
                    sprintf('Remote MIME type "%s" is not allowed.', $mime ?: 'unknown'),
                    $resolvedUrl,
                    $status,
                    $size,
                    $mime,
                );
            }

            if ($ext === '' && $mime) {
                $ext = $this->extensionFromMime($mime);
            }
            $ext = $ext !== '' ? $ext : 'bin';
            $filename = $filename ?: (Str::uuid()->toString().'.'.$ext);
            $storedPath = trim($directory, '/').'/'.$filename;

            if ($workspace && $size > 0) {
                $this->storage->assertCanStore($workspace, $size);
            }

            rewind($resource);
            if (! $this->files->pipeStream($resource, $storedPath, $disk)) {
                throw new RemoteDownloadException('Unable to store the remote file.', $resolvedUrl, $status, $size, $mime);
            }

            if ($workspace && $size > 0) {
                $this->storage->increaseUsage($workspace, $size);
            }

            return [$storedPath, $size, $mime];
        } finally {
            fclose($resource);
        }
    }

    protected function extensionFromMime(?string $mime): string
    {
        if (! $mime) {
            return 'bin';
        }

        return match ($mime) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'application/pdf' => 'pdf',
            default => 'bin',
        };
    }
}
