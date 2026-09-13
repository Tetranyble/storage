<?php

namespace Tetranyble\Storage\Http\Responses;

use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\ResourceNotFoundException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;

class MediaStreamResponder
{
    public function __construct(private readonly FileSystemContract $files) {}

    public function stream(Media $media): StreamedResponse
    {
        $stream = $this->files->disk($media->disk)->readStream($media->path);

        if (! $stream) {
            throw new ResourceNotFoundException('File not found.');
        }

        $mime = $media->mime_type ?: $this->files->mimeType($media->path, $media->disk) ?? 'application/octet-stream';
        $filename = basename((string) $media->path) ?: 'download';

        return response()->stream(function () use ($stream): void {
            while (! feof($stream)) {
                echo fread($stream, 8192);
                flush();
            }
            if (is_resource($stream)) {
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => $mime,
            'Content-Disposition' => (new ResponseHeaderBag)->makeDisposition('attachment', SafeDownloadFilename::from($filename), 'download'),
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
