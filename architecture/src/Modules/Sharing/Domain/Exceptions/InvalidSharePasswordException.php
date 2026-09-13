<?php

namespace Tetranyble\Storage\Modules\Sharing\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class InvalidSharePasswordException extends StorageException
{
    public function __construct(string $message = 'Invalid password')
    {
        parent::__construct($message);
    }
}
