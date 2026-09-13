<?php

namespace Tetranyble\Storage\Events;

final class StorageTelemetryRecorded
{
    public function __construct(
        public readonly string $type,
        public readonly string $name,
        public readonly int|float|null $value,
        public readonly array $context,
        public readonly string $level,
        public readonly \DateTimeImmutable $occurredAt,
    ) {}
}
