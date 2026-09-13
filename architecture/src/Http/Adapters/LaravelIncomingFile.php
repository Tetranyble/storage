<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Http\Adapters;

use Illuminate\Http\UploadedFile;
use Tetranyble\Storage\Modules\Storage\Application\DTO\IncomingFile;

final class LaravelIncomingFile
{
    public static function fromUploadedFile(UploadedFile $file): IncomingFile
    {
        $path = $file->getRealPath();
        if (! is_string($path) || $path === '') {
            throw new \InvalidArgumentException('Uploaded file does not expose a readable local path.');
        }

        return new IncomingFile(
            localPath: $path,
            originalName: $file->getClientOriginalName(),
            size: (int) ($file->getSize() ?? 0),
            clientMimeType: $file->getClientMimeType() ?: null,
            detectedMimeType: $file->getMimeType() ?: null,
        );
    }
}
