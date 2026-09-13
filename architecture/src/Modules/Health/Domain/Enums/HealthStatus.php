<?php

namespace Tetranyble\Storage\Modules\Health\Domain\Enums;

enum HealthStatus: string
{
    case OK = 'ok';
    case WARNING = 'warning';
    case CRITICAL = 'critical';

    public function severity(): int
    {
        return match ($this) {
            self::OK => 0,
            self::WARNING => 1,
            self::CRITICAL => 2,
        };
    }
}
