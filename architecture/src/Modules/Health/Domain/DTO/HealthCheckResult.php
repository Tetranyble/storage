<?php

namespace Tetranyble\Storage\Modules\Health\Domain\DTO;

use Tetranyble\Storage\Modules\Health\Domain\Enums\HealthStatus;

final class HealthCheckResult
{
    public function __construct(
        public readonly string $name,
        public readonly HealthStatus $status,
        public readonly string $summary,
        public readonly array $details = [],
    ) {}

    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'status' => $this->status->value,
            'summary' => $this->summary,
            'details' => $this->details,
        ];
    }
}
