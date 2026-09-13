<?php

namespace Tetranyble\Storage\Modules\Sharing\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class ShareDownloadLimitReachedException extends StorageException
{
    public function __construct(string $message = 'Download limit reached')
    {
        parent::__construct($message);
    }
}
