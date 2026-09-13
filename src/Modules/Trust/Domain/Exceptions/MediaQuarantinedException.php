<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Exceptions;

use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\StorageException;

class MediaQuarantinedException extends StorageException
{
    public function __construct(string $message = 'Media is quarantined and cannot be delivered yet.')
    {
        parent::__construct($message);
    }
}
