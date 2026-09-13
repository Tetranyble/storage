<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing;

use Tetranyble\Storage\Modules\Media\Domain\Enums\MediaDerivativeKind;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Persistence\Eloquent\Models\MediaDerivative;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\UnsafeMediaException;

class MediaPostProcessor
{
    private readonly ImageOrientationNormalizer $orientation;

    public function __construct(
        private readonly FileSystemContract $files,
        private readonly MediaDerivativeService $derivatives,
        ?ImageOrientationNormalizer $orientation = null,
    ) {
        $this->orientation = $orientation ?? new ImageOrientationNormalizer(new ExifOrientationReader);
    }

    public function process(Media $media, MediaUploadOptions $options): array
    {
        $results = ['media' => $media];

        if (! $this->isImage($media)) {
            return $results;
        }

        $maxBytes = (int) config('tetranyble-storage.derivatives.max_source_bytes', 20 * 1024 * 1024);
        try {
            $binary = $this->files->get((string) $media->path, $media->disk, $maxBytes);
        } catch (\Throwable) {
            return $results;
        }

        if (! function_exists('getimagesizefromstring')) {
            return $results;
        }

        $metadata = @getimagesizefromstring($binary);
        if (! is_array($metadata) || ! isset($metadata[0], $metadata[1])) {
            throw new UnsafeMediaException('Image content could not be decoded safely.');
        }

        $rawWidth = (int) $metadata[0];
        $rawHeight = (int) $metadata[1];
        $this->assertSafeDimensions($rawWidth, $rawHeight);

        $orientation = $this->orientation->orientation($binary, $media->mime_type);
        $displayWidth = in_array($orientation, [5, 6, 7, 8], true) ? $rawHeight : $rawWidth;
        $displayHeight = in_array($orientation, [5, 6, 7, 8], true) ? $rawWidth : $rawHeight;
        $this->assertSafeDimensions($displayWidth, $displayHeight);

        if (! function_exists('imagecreatefromstring') || ! function_exists('imagecreatetruecolor')) {
            $media->forceFill(['width' => $displayWidth, 'height' => $displayHeight])->save();

            return $results;
        }

        $image = @imagecreatefromstring($binary);
        if (! $image instanceof \GdImage) {
            throw new UnsafeMediaException('Image decoding failed after metadata validation.');
        }

        $image = $this->orientation->normalize($image, $orientation);
        $displayWidth = imagesx($image);
        $displayHeight = imagesy($image);
        $this->assertSafeDimensions($displayWidth, $displayHeight);

        $generated = [];
        try {
            foreach ($this->variantDefinitions() as $definition) {
                if (! $definition['enabled']) {
                    continue;
                }

                $variantResults = $this->generateVariant(
                    $media,
                    $image,
                    $displayWidth,
                    $displayHeight,
                    $definition,
                    $orientation,
                );
                array_push($generated, ...$variantResults);
            }
        } finally {
            imagedestroy($image);
        }

        $media->forceFill(['width' => $displayWidth, 'height' => $displayHeight])->save();
        $media->refresh();

        if ($generated !== []) {
            $results['derivatives'] = array_map(fn (MediaDerivative $item): array => $this->payload($item), $generated);
        }

        $primaryThumbnail = $this->derivatives->primary($media, MediaDerivativeKind::THUMBNAIL);
        if ($primaryThumbnail !== null) {
            $results['thumbnail'] = $primaryThumbnail->path;
        }

        return $results;
    }

    private function isImage(Media $media): bool
    {
        $mime = strtolower((string) ($media->mime_type ?? ''));

        return str_starts_with($mime, 'image/')
            && ! in_array($mime, ['image/svg+xml', 'image/x-icon'], true);
    }

    /**
     * @return list<array{kind:MediaDerivativeKind,variant:string,enabled:bool,width:int,height:int,formats:list<string>,primary_format:string,quality:int}>
     */
    private function variantDefinitions(): array
    {
        return [
            [
                'kind' => MediaDerivativeKind::THUMBNAIL,
                'variant' => 'default',
                'enabled' => (bool) config('tetranyble-storage.derivatives.thumbnail.enabled', true),
                'width' => max(1, (int) config('tetranyble-storage.derivatives.thumbnail.width', 320)),
                'height' => max(1, (int) config('tetranyble-storage.derivatives.thumbnail.height', 240)),
                'formats' => $this->formats(config('tetranyble-storage.derivatives.thumbnail.formats', ['jpeg'])),
                'primary_format' => $this->normalizeFormat((string) config('tetranyble-storage.derivatives.thumbnail.primary_format', 'jpeg')),
                'quality' => $this->quality((int) config('tetranyble-storage.derivatives.thumbnail.quality', 80)),
            ],
            [
                'kind' => MediaDerivativeKind::PREVIEW,
                'variant' => 'default',
                'enabled' => (bool) config('tetranyble-storage.derivatives.preview.enabled', false),
                'width' => max(1, (int) config('tetranyble-storage.derivatives.preview.width', 1600)),
                'height' => max(1, (int) config('tetranyble-storage.derivatives.preview.height', 1600)),
                'formats' => $this->formats(config('tetranyble-storage.derivatives.preview.formats', ['webp', 'jpeg'])),
                'primary_format' => $this->normalizeFormat((string) config('tetranyble-storage.derivatives.preview.primary_format', 'webp')),
                'quality' => $this->quality((int) config('tetranyble-storage.derivatives.preview.quality', 82)),
            ],
        ];
    }

    /** @return list<MediaDerivative> */
    private function generateVariant(
        Media $media,
        \GdImage $source,
        int $sourceWidth,
        int $sourceHeight,
        array $definition,
        int $orientation,
    ): array {
        [$width, $height] = $this->fitDimensions(
            $sourceWidth,
            $sourceHeight,
            $definition['width'],
            $definition['height'],
        );

        $canvas = imagecreatetruecolor($width, $height);
        imagesavealpha($canvas, true);
        $transparent = imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefill($canvas, 0, 0, $transparent);
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        $stored = [];
        try {
            foreach ($definition['formats'] as $format) {
                $encoded = $this->encode($canvas, $format, $definition['quality']);
                if ($encoded === null) {
                    continue;
                }

                [$binary, $mimeType] = $encoded;
                $existing = $this->derivatives->find($media, $definition['kind'], $definition['variant'], $format);
                if ($existing !== null && $this->derivatives->exists($existing)) {
                    $stored[] = $existing;

                    continue;
                }

                $stored[] = $this->derivatives->persistBinary(
                    media: $media,
                    kind: $definition['kind'],
                    variant: $definition['variant'],
                    format: $format,
                    mimeType: $mimeType,
                    binary: $binary,
                    width: $width,
                    height: $height,
                    primary: $format === $definition['primary_format'],
                    metadata: ['source_orientation' => $orientation],
                );
            }
        } finally {
            imagedestroy($canvas);
        }

        if ($stored !== [] && ! array_filter($stored, fn (MediaDerivative $item): bool => (bool) $item->is_primary)) {
            $stored[0] = $this->derivatives->makePrimary($stored[0]);
        }

        return $stored;
    }

    /** @return array{0:string,1:string}|null */
    private function encode(\GdImage $image, string $format, int $quality): ?array
    {
        $format = $this->normalizeFormat($format);
        ob_start();
        $ok = match ($format) {
            'jpeg' => function_exists('imagejpeg') && imagejpeg($image, null, $quality),
            'webp' => function_exists('imagewebp') && imagewebp($image, null, $quality),
            'avif' => function_exists('imageavif') && imageavif($image, null, $quality),
            'png' => function_exists('imagepng') && imagepng($image, null, max(0, min(9, (int) round((100 - $quality) / 11.111)))),
            default => false,
        };
        $binary = ob_get_clean();

        if (! $ok || ! is_string($binary) || $binary === '') {
            return null;
        }

        $mime = match ($format) {
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'png' => 'image/png',
            default => 'image/jpeg',
        };

        return [$binary, $mime];
    }

    private function assertSafeDimensions(int $width, int $height): void
    {
        $maxWidth = max(1, (int) config('tetranyble-storage.images.max_width', 12000));
        $maxHeight = max(1, (int) config('tetranyble-storage.images.max_height', 12000));
        $maxPixels = max(1, (int) config('tetranyble-storage.images.max_pixels', 40_000_000));

        if ($width < 1 || $height < 1 || $width > $maxWidth || $height > $maxHeight) {
            throw new UnsafeMediaException(sprintf(
                'Image dimensions %dx%d exceed the configured safety limits (%dx%d).',
                $width,
                $height,
                $maxWidth,
                $maxHeight,
            ));
        }

        if ($width * $height > $maxPixels) {
            throw new UnsafeMediaException(sprintf(
                'Image pixel count exceeds the configured safety limit (%d > %d).',
                $width * $height,
                $maxPixels,
            ));
        }
    }

    private function fitDimensions(int $width, int $height, int $maxWidth, int $maxHeight): array
    {
        if ($width <= $maxWidth && $height <= $maxHeight) {
            return [$width, $height];
        }

        $ratio = min($maxWidth / $width, $maxHeight / $height);

        return [max(1, (int) round($width * $ratio)), max(1, (int) round($height * $ratio))];
    }

    private function formats(mixed $formats): array
    {
        if (is_string($formats)) {
            $formats = array_filter(array_map('trim', explode(',', $formats)));
        }
        if (! is_array($formats)) {
            $formats = ['jpeg'];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($format): string => $this->normalizeFormat((string) $format), $formats),
            fn (string $format): bool => in_array($format, ['jpeg', 'webp', 'avif', 'png'], true),
        ))) ?: ['jpeg'];
    }

    private function normalizeFormat(string $format): string
    {
        $format = strtolower(trim($format));

        return $format === 'jpg' ? 'jpeg' : $format;
    }

    private function quality(int $quality): int
    {
        return max(1, min(100, $quality));
    }

    private function payload(MediaDerivative $derivative): array
    {
        return [
            'uuid' => $derivative->uuid,
            'kind' => $derivative->kind->value,
            'variant' => $derivative->variant,
            'format' => $derivative->format,
            'mime_type' => $derivative->mime_type,
            'path' => $derivative->path,
            'size' => $derivative->size,
            'width' => $derivative->width,
            'height' => $derivative->height,
            'is_primary' => $derivative->is_primary,
        ];
    }
}
