<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Storage\Domain\ValueObject;

use InvalidArgumentException;

final readonly class Sha256Checksum
{
    public string $value;

    public function __construct(string $value)
    {
        $value = strtolower(trim($value));
        if (preg_match('/^[a-f0-9]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException('SHA-256 checksum must contain exactly 64 hexadecimal characters.');
        }
        $this->value = $value;
    }

    public function matches(string $candidate): bool
    {
        return hash_equals($this->value, strtolower($candidate));
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
