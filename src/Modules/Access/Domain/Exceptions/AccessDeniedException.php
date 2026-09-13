<?php

namespace Tetranyble\Storage\Modules\Access\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class AccessDeniedException extends StorageException
{
    public function __construct(string $message = 'You are not allowed to perform this storage operation.')
    {
        parent::__construct($message);
    }
}
