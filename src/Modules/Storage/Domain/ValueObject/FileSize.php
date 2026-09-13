<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\ValueObject;

use InvalidArgumentException;

final readonly class FileSize
{
    public function __construct(public int $bytes)
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('File size cannot be negative.');
        }
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function isGreaterThan(self $other): bool
    {
        return $this->bytes > $other->bytes;
    }

    public function plus(self $other): self
    {
        return new self($this->bytes + $other->bytes);
    }

    public function minusFloorZero(self $other): self
    {
        return new self(max(0, $this->bytes - $other->bytes));
    }
}
