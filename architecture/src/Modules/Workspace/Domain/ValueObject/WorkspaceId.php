<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Domain\ValueObject;

use InvalidArgumentException;

final readonly class WorkspaceId
{
    public function __construct(public int|string $value)
    {
        if ((is_int($value) && $value <= 0) || (is_string($value) && trim($value) === '')) {
            throw new InvalidArgumentException('Workspace id must be a positive integer or non-empty string.');
        }
    }

    public function asString(): string
    {
        return (string) $this->value;
    }
}
