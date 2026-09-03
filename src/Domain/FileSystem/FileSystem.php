<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemTrait;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Illuminate\Contracts\Filesystem\Filesystem as LaravelFilesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FileSystem implements FileSystemContract
{
    use FileSystemTrait;

    protected FilesystemManager $storage;

    protected Disk $disk;

    public function __construct(FilesystemManager $storage)
    {
        $this->storage = $storage;
        $this->disk = Disk::default();
    }

    public function getDefaultDisk(): Disk
    {
        return $this->disk;
    }

    public function store(string|UploadedFile|File $file, string $directory = 'images', ?Disk $disk = null): string
    {
        $disk = $disk ?? $this->disk;
        $name = $this->uniqueName($file);

        return $this->storeAs($file, $name, $directory, $disk);
    }

    protected function uniqueName(string|UploadedFile|File|null $file, string $fallbackExtension = 'png'): string
    {
        $name = Str::uuid()->toString().'-'.now()->format('Y-m-d-H-i-s');

        if ($file instanceof UploadedFile) {
            return $name.'.'.$file->extension();
        }

        if ($file instanceof File) {
            return $name.'.'.$file->extension();
        }

        return $name.'.'.ltrim($fallbackExtension, '.');
    }

    public function storeAs(string|UploadedFile|File $file, string $name, string $directory = 'images', ?Disk $disk = null): string
    {
        $disk = $disk ?? $this->disk;

        $uploaded = $file instanceof UploadedFile
            ? $file
            : ($file instanceof File ? $file : new File($file));

        return $this->adapter($disk)->putFileAs(
            $directory,
            $uploaded,
            $name
        );
    }

    /**
     * Use the contract type here – FilesystemManager::disk() returns this.
     */
    protected function adapter(Disk $disk): LaravelFilesystem
    {
        return $this->storage->disk($disk->value);
    }

    public function disk(Disk $disk): self
    {
        $clone = clone $this;
        $clone->disk = $disk;

        return $clone;
    }

    public function makeDirectory(string $directory, ?Disk $disk = null): bool
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->makeDirectory($directory);
    }

    public function move(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool
    {
        $toDisk = $toDisk ?? $fromDisk;

        if ($fromDisk === $toDisk) {
            return $this->adapter($fromDisk)->move($from, $to);
        }

        if (! $this->copy($from, $to, $fromDisk, $toDisk)) {
            return false;
        }

        $this->adapter($fromDisk)->delete($from);

        return true;
    }

    public function copy(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool
    {
        $toDisk = $toDisk ?? $fromDisk;

        if ($fromDisk === $toDisk) {
            return $this->adapter($fromDisk)->copy($from, $to);
        }

        $stream = $this->adapter($fromDisk)->readStream($from);

        if (! $stream) {
            return false;
        }

        $result = $this->adapter($toDisk)->writeStream($to, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return (bool) $result;
    }

    public function readStream(string $path, ?Disk $disk = null)
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->readStream($path);
    }

    public function writeStream(string $path, $resource, ?Disk $disk = null, array $options = []): bool
    {
        $disk = $disk ?? $this->disk;

        return (bool) $this->adapter($disk)->writeStream($path, $resource, $options);
    }

    public function delete(string $path, ?Disk $disk = null): bool
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->delete($path);
    }

    public function exists(string $path, ?Disk $disk = null): bool
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->exists($path);
    }

    public function moveDirectory(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool
    {
        $toDisk = $toDisk ?? $fromDisk;

        if (! $this->copyDirectory($from, $to, $fromDisk, $toDisk)) {
            return false;
        }

        $this->adapter($fromDisk)->deleteDirectory($from);

        return true;
    }

    public function copyDirectory(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool
    {
        $toDisk = $toDisk ?? $fromDisk;

        $files = $this->adapter($fromDisk)->allFiles($from);

        foreach ($files as $file) {
            $relative = ltrim(Str::after($file, $from), '/');
            $target = rtrim($to, '/').'/'.$relative;

            if (! $this->copy($file, $target, $fromDisk, $toDisk)) {
                return false;
            }
        }

        return true;
    }

    public function deleteDirectory(string $directory, ?Disk $disk = null): bool
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->deleteDirectory($directory);
    }

    public function show(string $path, ?Disk $disk = null, int $ttlMinutes = 60): string
    {
        return $this->url($path, $disk, $ttlMinutes);
    }

    public function url(string $path, ?Disk $disk = null, int $ttlMinutes = 60): string
    {
        $disk = $disk ?? $this->disk;

        // Absolute URLs: don't touch
        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        // YouTube / Vimeo: path is already usable
        if (in_array($disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
            return $path;
        }

        if ($disk === Disk::S3PRIVATE) {
            return $this->storage
                ->disk($disk->value)
                ->temporaryUrl($path, now()->addMinutes($ttlMinutes));
        }

        if ($disk === Disk::PRIVATE) {
            return rtrim((string) config('app.url'), '/').$this->storage
                ->disk($disk->value)
                ->url($path);
        }

        return $this->storage
            ->disk($disk->value)
            ->url($path);
    }
    //    public function url(string $path, ?Disk $disk = null): string
    //    {
    //        $disk = $disk ?? $this->disk;
    //
    //        // Absolute URLs: don't touch
    //        if ($this->isAbsoluteUrl($path)) {
    //            return $path;
    //        }
    //
    //        // YouTube / Vimeo: treat stored path as already usable
    //        if ($disk && in_array($disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
    //            return $path;
    //        }
    //
    //        if ($disk === Disk::PRIVATE) {
    //            return rtrim(config('app.url'), '/') .
    //                $this->storage->disk($disk->value)->url($path);
    //        }
    //
    //        return $this->storage
    //            ->disk($disk->value)
    //            ->url($path);
    //    }

    public function path(string $path, ?Disk $disk = null): string
    {
        $disk = $disk ?? $this->disk;

        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        if (in_array($disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
            return $path;
        }

        return $this->adapter($disk)->path($path);
    }

    public function download(string $path, ?Disk $disk = null, ?string $name = null, array $headers = []): StreamedResponse
    {
        $disk = $disk ?? $this->disk;

        return $this->adapter($disk)->download($path, $name, $headers);
    }

    /**
     * Generic put helper: string => put, stream => writeStream.
     *
     * @param  string|resource|StreamInterface  $contents
     */
    public function put(string $path, $contents, ?Disk $disk = null, array $options = []): bool
    {
        $disk = $disk ?? $this->disk;

        if ($this->isStream($contents)) {
            return $this->writeStream($path, $contents, $disk, $options);
        }

        return (bool) $this->adapter($disk)->put($path, $contents, $options);
    }

    protected function isStream($value): bool
    {
        return is_resource($value) || $value instanceof StreamInterface;
    }

    /**
     * Pipe an existing readable stream into a file.
     *
     * @param  resource|StreamInterface  $stream
     */
    public function pipeStream($stream, string $path, ?Disk $disk = null, array $options = []): bool
    {
        return $this->writeStream($path, $stream, $disk, $options);
    }

    public function size(string $path, ?Disk $disk = null): int
    {
        $disk = $disk ?? $this->disk;

        if ($this->isAbsoluteUrl($path)) {
            return 0;
        }

        return $this->adapter($disk)->size($path);
    }

    public function mimeType(string $path, ?Disk $disk = null): ?string
    {
        $disk = $disk ?? $this->disk;

        if ($this->isAbsoluteUrl($path)) {
            return null;
        }

        return $this->adapter($disk)->mimeType($path);
    }

    public function signedUrl(
        string $path,
        ?Disk $disk = null,
        int $ttlMinutes = 60,
        array $options = []
    ): string {
        $disk = $disk ?? $this->disk;

        // Absolute URLs: nothing to sign
        if ($this->isAbsoluteUrl($path)) {
            return $path;
        }

        // Non-signable special media types
        if ($disk && in_array($disk, [Disk::YOUTUBE, Disk::VIMEO], true)) {
            return $path;
        }

        $adapter = $this->storage->disk($disk->value);

        // S3/Minio/etc may support temporary URLs.
        // Some drivers (e.g. local) expose temporaryUrl() but can still throw at runtime.
        if (method_exists($adapter, 'temporaryUrl')) {
            try {
                return $adapter->temporaryUrl(
                    $path,
                    now()->addMinutes($ttlMinutes),
                    $options
                );
            } catch (\Throwable) {
                // Graceful fallback for drivers without real temporary URL support.
            }
        }

        // Fallback: just return a normal URL (local/public, Google Drive, OneDrive, etc.)
        return $this->url($path, $disk);
    }

    public function get(string $path, ?Disk $disk = null, ?int $maxSizeBytes = null): string
    {
        if ($this->isAbsoluteUrl($path)) {
            throw new \RuntimeException(
                'FileSystem::get() only reads configured storage paths. '
                .'Use RemoteMediaImporter for remote HTTP/HTTPS content.'
            );
        }

        $disk = $disk ?? $this->disk;
        $maxSize = $maxSizeBytes ?? (int) config(
            'tetranyble-storage.reads.max_size',
            50 * 1024 * 1024,
        );

        if ($maxSize > 0) {
            $size = $this->size($path, $disk);

            if ($size > $maxSize) {
                throw new \RuntimeException(sprintf(
                    'File too large for get() (%d bytes, max %d bytes): %s',
                    $size,
                    $maxSize,
                    $path
                ));
            }
        }

        return $this->adapter($disk)->get($path);
    }

    public function publicUrl(string $path, ?Disk $disk = null): string
    {
        // Explicitly “I want a non-signed URL”.
        // Delegates to url(), which never calls temporaryUrl().
        return $this->url($path, $disk);
    }
}
