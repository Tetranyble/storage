<?php

namespace Tetranyble\Storage\Domain\FileSystem\Contracts;

use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Illuminate\Http\File;
use Illuminate\Http\UploadedFile;
use Psr\Http\Message\StreamInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface FileSystemContract
{
    public function disk(Disk $disk): self;

    public function getDefaultDisk(): Disk;

    public function store(string|UploadedFile|File $file, string $directory = 'images', ?Disk $disk = null): string;

    public function storeAs(string|UploadedFile|File $file, string $name, string $directory = 'images', ?Disk $disk = null): string;

    public function delete(string $path, ?Disk $disk = null): bool;

    public function exists(string $path, ?Disk $disk = null): bool;

    public function deleteDirectory(string $directory, ?Disk $disk = null): bool;

    public function makeDirectory(string $directory, ?Disk $disk = null): bool;

    public function copy(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool;

    public function move(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool;

    public function copyDirectory(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool;

    public function moveDirectory(string $from, string $to, Disk $fromDisk, ?Disk $toDisk = null): bool;

    public function url(string $path, ?Disk $disk = null, int $ttlMinutes = 60): string;

    public function path(string $path, ?Disk $disk = null): string;

    public function download(string $path, ?Disk $disk = null, ?string $name = null, array $headers = []): StreamedResponse;

    /**
     * Write a stream to disk (for large/binary content).
     *
     * @param  resource|StreamInterface  $resource
     */
    public function writeStream(string $path, $resource, ?Disk $disk = null, array $options = []): bool;

    /**
     * Read the full contents of a file from disk.
     *
     * @throws \RuntimeException if reading fails or path is an external URL.
     */
    public function get(string $path, ?Disk $disk = null, ?int $maxSizeBytes = null): string;

    /**
     * Generic put helper for small/medium payloads.
     *
     * @param  string|resource|StreamInterface  $contents
     */
    public function put(string $path, $contents, ?Disk $disk = null, array $options = []): bool;

    /**
     * Convenience: pipe an existing readable stream into a file.
     *
     * @param  resource|StreamInterface  $stream
     */
    public function pipeStream($stream, string $path, ?Disk $disk = null, array $options = []): bool;

    /**
     * @return resource|false
     */
    public function readStream(string $path, ?Disk $disk = null);

    public function size(string $path, ?Disk $disk = null): int;

    public function mimeType(string $path, ?Disk $disk = null): ?string;

    public function signedUrl(string $path, ?Disk $disk = null, int $ttlMinutes = 60, array $options = []): string;

    /**
     * Always avoid signing, even if the driver supports temporary URLs.
     * This is essentially a semantic alias for url(), but gives you an explicit
     * "I want a non-expiring / non-signed link" call site.
     */
    public function publicUrl(string $path, ?Disk $disk = null): string;
}
