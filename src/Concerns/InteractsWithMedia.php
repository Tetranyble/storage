<?php

namespace Tetranyble\Storage\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Enums\UploadStrategy;
use Tetranyble\Storage\Domain\FileSystem\MediaService;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Enums\MediaStatus;
use Tetranyble\Storage\Models\Media;

trait InteractsWithMedia
{
    public function medium(): MorphOne
    {
        return $this->morphOne($this->mediaModelClass(), 'mediable')->latestOfMany();
    }

    public function media(): MorphMany
    {
        return $this->morphMany($this->mediaModelClass(), 'mediable');
    }

    public function images(): MorphMany
    {
        return $this->media()->where('mime_type', 'like', 'image/%');
    }

    public function videos(): MorphMany
    {
        return $this->media()->where('mime_type', 'like', 'video/%');
    }

    protected function mediaService(): MediaService
    {
        return app(MediaService::class);
    }

    protected function mediaModelClass(): string
    {
        $model = config('tetranyble-storage.models.media', Media::class);
        if (! is_string($model) || ! is_a($model, Media::class, true)) {
            throw new RuntimeException('The configured storage media model must extend '.Media::class.'.');
        }

        return $model;
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
        $model = $this->mediaModelClass();
        $media = $model::query()->find($mediaId);

        return $media ? $this->attachExistingMedia($media, $purpose, $replaceExisting, $directory) : null;
    }

    public function currentMedia(?MediaPurpose $purpose = null): ?Media
    {
        return $this->media()
            ->where('current', true)
            ->when($purpose, fn ($query) => $query->where('use', $purpose))
            ->latest()
            ->first();
    }

    public function setCurrentMediaItem(string|int|Media $media): Media
    {
        return $this->mediaService()->setCurrentMedia($this->ownedMedia($media));
    }

    public function mediaForPurpose(MediaPurpose $purpose, bool $currentOnly = true)
    {
        return $this->media()
            ->where('use', $purpose)
            ->when($currentOnly, fn ($query) => $query->where('current', true))
            ->latest()
            ->get();
    }

    public function findMedia(string|int $key, bool $withTrashed = false): ?Media
    {
        $query = $this->media();
        if ($withTrashed) {
            $query->withTrashed();
        }

        return $query
            ->where(fn ($builder) => $builder->whereKey($key)->orWhere('uuid', $key))
            ->first();
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

    public function default(string $type = 'image'): Model
    {
        $defaults = config('tetranyble-storage.defaults.'.($type === 'video' ? 'video' : 'image'), []);
        $model = $this->mediaModelClass();

        return new $model([
            'path' => $defaults['path'] ?? null,
            'disk' => $defaults['disk'] ?? Disk::PUBLIC->value,
        ]);
    }

    public function getImageAttribute(): Media|Model
    {
        return $this->images()->where('current', true)->latest()->first() ?? $this->default();
    }

    public function getVideoAttribute(): Media|Model
    {
        return $this->videos()->where('current', true)->latest()->first() ?? $this->default('video');
    }

    public function getFaviconAttribute(): ?string
    {
        return $this->currentMedia(MediaPurpose::FAVICON)?->url
            ?? config('tetranyble-storage.defaults.image.path');
    }

    public function getLogoAttribute(): ?string
    {
        return $this->currentMedia(MediaPurpose::LOGO)?->url
            ?? config('tetranyble-storage.defaults.image.path');
    }

    public function getProfileAttribute(): Media|Model
    {
        return $this->currentMedia(MediaPurpose::PROFILE) ?? $this->default();
    }

    public function idDocument(): MorphOne
    {
        return $this->morphOne($this->mediaModelClass(), 'mediable')
            ->where('use', MediaPurpose::NEXT_OF_KIN_ID);
    }

    public function getKycFrontAttribute(): ?Media
    {
        return $this->currentMedia(MediaPurpose::IDENTITY_DOCUMENT_FRONT);
    }

    public function getKycBackAttribute(): ?Media
    {
        return $this->currentMedia(MediaPurpose::IDENTITY_DOCUMENT_BACK);
    }

    public function workspaceLogo(): ?string
    {
        return $this->media()
            ->where('current', true)
            ->where('use', MediaPurpose::LOGO)
            ->where('status', MediaStatus::READY)
            ->latest()
            ->first()?->url;
    }

    private function ownedMedia(string|int|Media $media, bool $withTrashed = false): Media
    {
        $resolved = $media instanceof Media
            ? $this->findMedia($media->getKey(), $withTrashed)
            : $this->findMedia($media, $withTrashed);

        abort_unless($resolved instanceof Media, 404);

        return $resolved;
    }
}
