<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\Exceptions;

use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\StorageException;

class DirectUploadConflictException extends StorageException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'direct_upload_conflict',
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }
}
