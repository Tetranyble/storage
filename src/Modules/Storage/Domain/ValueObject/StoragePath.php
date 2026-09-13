<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\ValueObject;

use InvalidArgumentException;

final readonly class StoragePath
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim(str_replace('\\', '/', $value));
        $value = ltrim($value, '/');

        if ($value === '' || str_contains($value, "\0")) {
            throw new InvalidArgumentException('Storage path must be non-empty and contain no null bytes.');
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '..') {
                throw new InvalidArgumentException('Storage path cannot contain parent-directory traversal.');
            }
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
