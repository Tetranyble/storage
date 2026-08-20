<?php

namespace Tetranyble\Storage\Facades;

use Tetranyble\Storage\Domain\FileSystem\Contracts\MediaUploader;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Enums\MediaPurpose;
use Tetranyble\Storage\Models\Media;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Facade;

/**
 * @method static Media uploadFor(Model $model, UploadedFile $file, string $description = '', string $attribution = '', string $directory = 'media', MediaPurpose $purpose = MediaPurpose::GENERAL, ?Disk $disk = null, bool $replaceExisting = false, bool $makeCurrent = true)
 * @method static Media uploadUploadedFile(UploadedFile $file, MediaUploadOptions $options)
 * @method static Media uploadStandalone(UploadedFile $file, string $description = '', string $attribution = '', string $directory = 'uploads', ?MediaPurpose $purpose = null, ?Disk $disk = null, ?int $workspaceId = null, ?\DateTimeInterface $expiresAt = null)
 * @method static Media uploadStandaloneFromUrl(string $url, ?MediaPurpose $purpose = null, ?Disk $disk = null, ?string $description = null, ?string $attribution = null, string $directory = 'uploads', ?int $workspaceId = null, ?int $maxSizeBytes = null, ?array $allowedMimes = null, ?\DateTimeInterface $expiresAt = null)
 * @method static Media uploadFromUrl(string $url, MediaUploadOptions $options, ?int $maxSizeBytes = null, ?array $allowedMimes = null)
 * @method static Media attachPathFor(Model $model, string $path, string $description = '', string $attribution = '', string $directory = 'media', MediaPurpose $purpose = MediaPurpose::GENERAL, ?Disk $disk = null, bool $replaceExisting = false, bool $preserveFilename = false)
 * @method static Media attachExternalFor(Model $model, string $url, MediaPurpose $purpose = MediaPurpose::GENERAL, ?Disk $disk = null, ?string $description = null, ?string $attribution = null, bool $replaceExisting = true)
 * @method static Media attachSourceFor(Model $model, UploadedFile|string $source, string $description = '', string $attribution = '', string $directory = 'media', MediaPurpose $purpose = MediaPurpose::GENERAL, ?Disk $disk = null, bool $replaceExisting = false, bool $preserveFilenameForPath = false, ?int $maxSizeBytes = null, ?array $allowedMimes = null, bool $makeCurrent = true)
 * @method static Media setCurrentMedia(Media $media)
 * @method static Media attachExistingMediaToModel(Media $media, Model $model, MediaPurpose $purpose = MediaPurpose::GENERAL, bool $replaceExisting = false, string $directory = 'media')
 * @method static Media createRevisionFromUpload(Media $media, UploadedFile $file, ?int $userId = null)
 * @method static Media restoreRevision(Media $revision, ?int $userId = null)
 * @method static void  deleteMediaItem(Media $media)
 * @method static void  clearMedia(Model $model)
 * @method static void  purgeMedia(Model $model)
 * @method static \Illuminate\Database\Eloquent\Collection revisionsFor(Media $media)
 * @method static \Illuminate\Database\Eloquent\Collection revisionActivityFor(Media $media)
 *
 * @see \Tetranyble\Storage\Domain\FileSystem\MediaService
 */
class MediaUpload extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MediaUploader::class;
    }
}
