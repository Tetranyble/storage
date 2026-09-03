<?php

namespace Tetranyble\Storage\Domain\FileSystem;

use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;
use Tetranyble\Storage\Contracts\RemoteUrlValidator;
use Tetranyble\Storage\Domain\FileSystem\Contracts\FileSystemContract;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;
use Tetranyble\Storage\Domain\FileSystem\Exceptions\RemoteDownloadException;

/**
 * Streams validated remote content into package-managed storage.
 *
 * MediaService owns media-record orchestration; this collaborator owns only the
 * HTTP/redirect/MIME/size/storage transfer concerns of a remote download.
 */
class RemoteMediaDownloadService
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly StorageService $storage,
        private readonly StorageOrphanService $orphans,
        private readonly RemoteUrlValidator $remoteUrlValidator,
    ) {}

    /**
     * @return array{0:string,1:int,2:string|null}
     */
    public function download(
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
            try {
                if (! $this->files->pipeStream($resource, $storedPath, $disk)) {
                    throw new RemoteDownloadException('Unable to store the remote file.', $resolvedUrl, $status, $size, $mime);
                }
            } catch (Throwable $exception) {
                // A streaming adapter can fail after writing only part of the
                // destination object. The path is already deterministic here,
                // so compensate it before surfacing the transport failure.
                $this->orphans->deleteOrTrack(
                    $disk,
                    $storedPath,
                    $workspace?->getKey() ? (int) $workspace->getKey() : null,
                    $size > 0 ? $size : null,
                    'remote_download_rollback',
                );

                if ($exception instanceof RemoteDownloadException) {
                    throw $exception;
                }

                throw new RemoteDownloadException(
                    'Unable to store the remote file.',
                    $resolvedUrl,
                    $status,
                    $size,
                    $mime,
                    previous: $exception,
                );
            }

            // Quota is reserved by StorageLifecycleService when the downloaded
            // object is committed to a Media record. Keeping the increment out
            // of this transport service makes DB persistence failure compensable.
            return [$storedPath, $size, $mime];
        } finally {
            fclose($resource);
        }
    }

    private function extensionFromMime(?string $mime): string
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
