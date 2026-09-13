<?php

namespace Tetranyble\Storage\Modules\Observability\Domain\Contracts;

use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

interface StorageTelemetry
{
    public function event(string $name, array $context = [], TelemetryLevel $level = TelemetryLevel::INFO): void;

    public function counter(string $name, int $value = 1, array $dimensions = []): void;

    public function gauge(string $name, int|float $value, array $dimensions = []): void;

    public function timing(string $name, float $milliseconds, array $dimensions = []): void;
}
