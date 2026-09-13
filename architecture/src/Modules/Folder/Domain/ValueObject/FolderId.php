<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Folder\Domain\ValueObject;

use InvalidArgumentException;

final readonly class FolderId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Folder id must be a positive integer.');
        }
    }
}
