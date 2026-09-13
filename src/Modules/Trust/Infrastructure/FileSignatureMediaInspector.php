<?php

namespace Tetranyble\Storage\Modules\Trust\Infrastructure;

use RuntimeException;
use Tetranyble\Storage\Modules\Storage\Application\Contracts\FileSystemContract;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaContentInspection;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

class FileSignatureMediaInspector implements MediaContentInspector
{
    public function __construct(
        private readonly FileSystemContract $files,
        private readonly int $prefixBytes = 64 * 1024,
    ) {}

    public function inspect(MediaScanTarget $target): MediaContentInspection
    {
        $stream = $this->files->readStream($target->path->value, $target->disk);
        if (! is_resource($stream)) {
            throw new RuntimeException('Unable to open stored media for content-signature inspection.');
        }

        try {
            $prefixBytes = max(512, min(1024 * 1024, $this->prefixBytes));
            $prefix = stream_get_contents($stream, $prefixBytes);
            if (! is_string($prefix)) {
                throw new RuntimeException('Unable to read stored media for content-signature inspection.');
            }
            if ($prefix === '') {
                return new MediaContentInspection('application/x-empty');
            }

            $finfo = new \finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->buffer($prefix);

            return new MediaContentInspection(is_string($mime) && $mime !== '' ? $mime : null);
        } finally {
            fclose($stream);
        }
    }
}
