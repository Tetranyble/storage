<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Trust\Domain\ValueObject;

use InvalidArgumentException;

final readonly class MediaScanId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Media scan id must be a positive integer.');
        }
    }
}
