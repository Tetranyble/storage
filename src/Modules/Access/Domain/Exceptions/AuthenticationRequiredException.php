<?php

namespace Tetranyble\Storage\Modules\Access\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class AuthenticationRequiredException extends StorageException
{
    public function __construct(string $message = 'Authentication is required for this storage operation.')
    {
        parent::__construct($message);
    }
}
