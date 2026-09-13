<?php

namespace Tetranyble\Storage\Concerns;

use Illuminate\Http\UploadedFile;
use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaPurpose;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\MediaService;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Upload\Domain\Enums\UploadStrategy;
use Tetranyble\Storage\Support\StorageConfig;

trait ManipulatesMedia
{
    protected function mediaService(): MediaService
    {
        return app(MediaService::class);
    }

    public function uploadMediaFile(
        UploadedFile $file,
        string $description = '',
        string $attribution = '',
        string $directory = 'images',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        array $options = [],
    ): Media {
        return $this->mediaService()->uploadUploadedFile(
            $file,
            MediaUploadOptions::forModel(
                model: $this,
                workspaceId: StorageConfig::actorWorkspaceId($this),
                purpose: $purpose,
                directory: $directory,
                disk: $disk,
                userId: isset($options['user_id']) ? (int) $options['user_id'] : null,
                module: (string) ($options['module'] ?? $directory),
                replaceExisting: $replaceExisting,
                customProperties: (array) ($options['custom_properties'] ?? []),
                strategy: ! empty($options['chunked']) ? UploadStrategy::CHUNKED : UploadStrategy::SINGLE,
                label: $description,
                title: $options['title'] ?? null,
                attribution: $attribution,
                folderId: isset($options['folder_id']) ? (int) $options['folder_id'] : null,
                makeCurrent: (bool) ($options['make_current'] ?? true),
            ),
        );
    }

    public function replaceMediaFile(
        UploadedFile $file,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        string $directory = 'media',
        ?Disk $disk = null,
        array $options = [],
    ): Media {
        return $this->uploadMediaFile(
            file: $file,
            description: (string) ($options['description'] ?? ''),
            attribution: (string) ($options['attribution'] ?? ''),
            directory: $directory,
            purpose: $purpose,
            disk: $disk,
            replaceExisting: true,
            options: $options,
        );
    }

    public function attachExternalMedia(
        string $url,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        ?string $description = null,
        ?string $attribution = null,
        bool $replaceExisting = true,
        bool $makeCurrent = true,
    ): Media {
        return $this->mediaService()->attachExternalFor(
            $this,
            $url,
            $purpose,
            $disk,
            $description,
            $attribution,
            $replaceExisting,
            $makeCurrent,
        );
    }

    public function attachMedia(
        UploadedFile|string $source,
        string $description = '',
        string $attribution = '',
        string $directory = 'images',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        bool $preserveFilenameForPath = false,
        ?int $maxSizeBytes = null,
        ?array $allowedMimes = null,
        bool $makeCurrent = true,
        ?Disk $storageDriver = null,
    ): Media {
        return $this->mediaService()->attachSourceFor(
            $this,
            $source,
            $description,
            $attribution,
            $directory,
            $purpose,
            $storageDriver ?? $disk,
            $replaceExisting,
            $preserveFilenameForPath,
            $maxSizeBytes,
            $allowedMimes,
            $makeCurrent,
        );
    }

    public function attachMediaFromPath(
        string $path,
        string $description = '',
        string $attribution = '',
        string $directory = 'images',
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        ?Disk $disk = null,
        bool $replaceExisting = false,
        bool $preserveFilename = false,
        bool $makeCurrent = true,
    ): Media {
        return $this->mediaService()->attachPathFor(
            $this,
            $path,
            $description,
            $attribution,
            $directory,
            $purpose,
            $disk,
            $replaceExisting,
            $preserveFilename,
            $makeCurrent,
        );
    }

    public function attachExistingMedia(
        Media $media,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        bool $replaceExisting = false,
        string $directory = 'media',
        bool $makeCurrent = true,
    ): Media {
        return $this->mediaService()->attachExistingMediaToModel(
            $media,
            $this,
            $purpose,
            $replaceExisting,
            $directory,
            $makeCurrent,
        );
    }

    public function attachExistingMediaById(
        int $mediaId,
        MediaPurpose $purpose = MediaPurpose::GENERAL,
        bool $replaceExisting = false,
        string $directory = 'media',
    ): ?Media {
        $media = Media::query()->find($mediaId);

        return $media ? $this->attachExistingMedia($media, $purpose, $replaceExisting, $directory) : null;
    }

    public function setCurrentMediaItem(string|int|Media $media): Media
    {
        return $this->mediaService()->setCurrentMedia($this->ownedMedia($media));
    }

    public function updateMediaMetadata(string|int|Media $media, array $attributes): Media
    {
        $resolved = $this->ownedMedia($media, true);
        $allowed = array_intersect_key($attributes, array_flip([
            'description',
            'attribution',
            'custom_properties',
        ]));

        $resolved->fill($allowed)->save();

        return $resolved->refresh();
    }

    public function trashMediaItem(string|int|Media $media): bool
    {
        return (bool) $this->ownedMedia($media)->delete();
    }

    public function restoreMediaItem(string|int|Media $media): Media
    {
        $resolved = $this->ownedMedia($media, true);
        if (method_exists($resolved, 'restore') && $resolved->trashed()) {
            $resolved->restore();
        }

        return $resolved->refresh();
    }

    public function deleteMediaItem(string|int|Media $media): void
    {
        $this->mediaService()->deleteMediaItem($this->ownedMedia($media, true));
    }

    public function clearMedia(): mixed
    {
        $this->mediaService()->clearMedia($this);

        return $this->media;
    }

    public function purgeMedia(): void
    {
        $this->mediaService()->purgeMedia($this);
    }

    public function disableMedia(): int|bool
    {
        $this->mediaService()->disableMedia($this);

        return $this->media()->update(['current' => false]);
    }

    public function removeMedia(Media|string $media): bool
    {
        return $this->trashMediaItem($media);
    }

    private function ownedMedia(string|int|Media $media, bool $withTrashed = false): Media
    {
        $resolved = $media instanceof Media
            ? $this->findMedia($media->getKey(), $withTrashed)
            : $this->findMedia($media, $withTrashed);

        if (! $resolved instanceof Media) {
            throw new ResourceNotFoundException;
        }

        return $resolved;
    }
}
