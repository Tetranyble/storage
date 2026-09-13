<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\ValueObject;

use InvalidArgumentException;

final readonly class MimeType
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim(explode(';', $value, 2)[0]));
        if ($value === '' || preg_match('#^[a-z0-9][a-z0-9!#$&^_.+-]*/[a-z0-9][a-z0-9!#$&^_.+-]*$#i', $value) !== 1) {
            throw new InvalidArgumentException('Invalid MIME type.');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
