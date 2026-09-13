<?php

namespace Tetranyble\Storage\Modules\Shared\Domain\Exceptions;

class ResourceNotFoundException extends StorageException
{
    public function __construct(string $message = 'Storage resource not found.')
    {
        parent::__construct($message);
    }
}
