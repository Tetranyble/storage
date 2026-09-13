<?php

namespace Tetranyble\Storage\Modules\Sharing\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException;

class ShareDownloadNotAllowedException extends StorageException
{
    public function __construct(string $message = 'This share does not allow downloads.')
    {
        parent::__construct($message);
    }
}
