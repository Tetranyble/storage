<?php

namespace Tetranyble\Storage\Domain\FileSystem\Exceptions;

use RuntimeException;

class RemoteDownloadException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $url,
        public readonly ?int $status = null,
        public readonly ?int $size = null,
        public readonly ?string $mime = null,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
