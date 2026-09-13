<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject;

use InvalidArgumentException;

final readonly class PartNumber
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Upload part number must be positive.');
        }
    }
}
