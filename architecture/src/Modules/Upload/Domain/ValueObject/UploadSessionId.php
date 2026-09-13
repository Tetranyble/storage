<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Domain\ValueObject;

use InvalidArgumentException;

final readonly class UploadSessionId
{
    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);
        if ($value === '') {
            throw new InvalidArgumentException('Upload session id cannot be empty.');
        }
        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
