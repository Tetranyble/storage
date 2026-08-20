<?php

namespace Tetranyble\Storage\Domain\FileSystem\Exceptions;

use RuntimeException;

class UploadSessionConflictException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
