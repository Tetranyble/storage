<?php

namespace Tetranyble\Storage\Domain\Media;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\DTO\MediaUploadOptions;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Models\Media;

class MediaPostProcessor
{
    public function __construct(
        private readonly FileSystemContract $files,
    ) {}

    public function dispatch(Media $media, MediaUploadOptions $options): void
    {
        $this->process($media, $options);
    }

    public function process(Media $media, MediaUploadOptions $options): array
    {
        $results = [];

        if ($this->isImage($media)) {
            $thumbnail = $this->generateThumbnail($media);
            if ($thumbnail) {
                $results['thumbnail'] = $thumbnail;
            }
        }

        return ['media' => $media, ...$results];
    }

    private function isImage(Media $media): bool
    {
        $mime = $media->mime_type ?? '';

        return str_starts_with(strtolower($mime), 'image/')
            && ! in_array(strtolower($mime), ['image/svg+xml', 'image/x-icon'], true);
    }

    private function generateThumbnail(Media $media): ?string
    {
        if (! $media->path || ! ($media->disk instanceof Disk)) {
            return null;
        }

        $maxBytes = (int) config('tetranyble-storage.thumbnails.max_source_bytes', 20 * 1024 * 1024);

        try {
            $binary = $this->files->get($media->path, $media->disk, $maxBytes);
        } catch (\Throwable) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if (! $image) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $thumbWidth = (int) config('tetranyble-storage.thumbnails.width', 320);
        $thumbHeight = (int) config('tetranyble-storage.thumbnails.height', 240);

        [$newWidth, $newHeight] = $this->fitDimensions($width, $height, $thumbWidth, $thumbHeight);

        $thumb = imagecreatetruecolor($newWidth, $newHeight);

        // Preserve transparency for PNG/GIF
        imagesavealpha($thumb, true);
        $transparent = imagecolorallocatealpha($thumb, 0, 0, 0, 127);
        imagefill($thumb, 0, 0, $transparent);

        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        imagejpeg($thumb, null, (int) config('tetranyble-storage.thumbnails.quality', 80));
        $thumbBinary = ob_get_clean();

        imagedestroy($image);
        imagedestroy($thumb);

        $thumbPath = $this->thumbnailPath($media->path);

        try {
            $this->files->put($thumbPath, $thumbBinary, $media->disk);
        } catch (\Throwable) {
            return null;
        }

        $media->forceFill(['thumbnail_path' => $thumbPath])->save();

        return $thumbPath;
    }

    private function thumbnailPath(string $originalPath): string
    {
        $dir = trim(dirname($originalPath), '/');
        $dir = $dir === '.' ? '' : $dir;
        $filename = pathinfo($originalPath, PATHINFO_FILENAME);
        $thumbDir = $dir !== '' ? $dir.'/.thumbnails' : '.thumbnails';

        return $thumbDir.'/'.$filename.'.jpg';
    }

    private function fitDimensions(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return [$width, $height];
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }
}
