<?php

namespace Tetranyble\Storage\Modules\Sharing\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class ShareExpiredException extends StorageException
{
    public function __construct(string $message = 'Link expired')
    {
        parent::__construct($message);
    }
}
