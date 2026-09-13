<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing;

final class ImageOrientationNormalizer
{
    public function __construct(private readonly ExifOrientationReader $reader) {}

    public function orientation(string $binary, ?string $mimeType = null): int
    {
        return $this->reader->orientation($binary, $mimeType);
    }

    public function normalize(\GdImage $image, int $orientation): \GdImage
    {
        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip($this->rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $this->rotate($image, -90),
            7 => $this->flip($this->rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    private function rotate(\GdImage $image, int $angle): \GdImage
    {
        $rotated = imagerotate($image, $angle, 0);
        if (! $rotated instanceof \GdImage) {
            return $image;
        }

        if ($rotated !== $image) {
            imagedestroy($image);
        }

        return $rotated;
    }

    private function flip(\GdImage $image, int $mode): \GdImage
    {
        if (function_exists('imageflip')) {
            imageflip($image, $mode);
        }

        return $image;
    }
}
