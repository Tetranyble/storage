<?php

namespace Tetranyble\Storage\Modules\Observability\Infrastructure;

use Tetranyble\Storage\Modules\Observability\Domain\Contracts\StorageTelemetry;
use Tetranyble\Storage\Modules\Observability\Domain\Enums\TelemetryLevel;

final class NullStorageTelemetry implements StorageTelemetry
{
    public function event(string $name, array $context = [], TelemetryLevel $level = TelemetryLevel::INFO): void {}

    public function counter(string $name, int $value = 1, array $dimensions = []): void {}

    public function gauge(string $name, int|float $value, array $dimensions = []): void {}

    public function timing(string $name, float $milliseconds, array $dimensions = []): void {}
}
