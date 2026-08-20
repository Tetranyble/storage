<?php

namespace Tetranyble\Storage\Domain\FileSystem\Contracts;

use Illuminate\Http\File;
use Illuminate\Support\Str;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

trait FileSystemTrait
{
    public function createMediaName(string $name = ''): string
    {
        return Str::uuid()->toString();
    }

    /**
     * Find a file under storage_path($path) by its filename.
     */
    public function getLocalFilePath(string $filename, string $path = 'uploads'): string|false
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(storage_path($path), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getFilename() === $filename) {
                return $file->getPathname();
            }
        }

        return false;
    }

    public function name(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME);
    }

    public function filename(string $path): string
    {
        return basename($path);
    }

    public function renameFile(string $file, string $name): string
    {
        $resource = new File($file);
        $slug = Str::slug($name);

        if ($slug === '') {
            return str_replace('.', '-', microtime(true)).Str::uuid()->toString().'.'.$resource->extension();
        }

        return $slug.'.'.$resource->extension();
    }

    /**
     * Check if the given path is already an absolute URL.
     */
    protected function isAbsoluteUrl(string $path): bool
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
