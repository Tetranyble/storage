<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\Exceptions;

use Tetranyble\Storage\Modules\Shared\Domain\Exceptions\StorageException as SharedStorageException;

/** @deprecated Prefer the Shared-kernel base for cross-capability exception handling. */
abstract class StorageException extends SharedStorageException {}
