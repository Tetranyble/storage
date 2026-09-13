<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\ValueObject;

use InvalidArgumentException;

final readonly class ETag
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('ETag cannot be empty.');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
