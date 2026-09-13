<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Shared\Domain\Exceptions;

use RuntimeException;

/** Base exception for framework-neutral package failures across capabilities. */
abstract class StorageException extends RuntimeException
{
}
