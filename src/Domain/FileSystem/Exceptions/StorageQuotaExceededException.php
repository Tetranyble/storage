<?php

namespace Tetranyble\Storage\Domain\FileSystem\Exceptions;

use RuntimeException;

class StorageQuotaExceededException extends RuntimeException
{
    public function __construct(
        public readonly int $requestedBytes,
        public readonly int $usedBytes,
        public readonly int $quotaBytes,
        string $message = ''
    ) {
        $message = $message ?: sprintf(
            'Storage quota exceeded: requested=%d, used=%d, quota=%d.',
            $requestedBytes,
            $usedBytes,
            $quotaBytes
        );

        parent::__construct($message);
    }
}
