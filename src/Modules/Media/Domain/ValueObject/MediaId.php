<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Domain\ValueObject;

use InvalidArgumentException;

final readonly class MediaId
{
    public function __construct(public int $value)
    {
        if ($value <= 0) {
            throw new InvalidArgumentException('Media id must be a positive integer.');
        }
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
