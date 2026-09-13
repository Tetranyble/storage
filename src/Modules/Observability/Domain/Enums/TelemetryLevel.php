<?php

namespace Tetranyble\Storage\Modules\Observability\Domain\Enums;

enum TelemetryLevel: string
{
    case DEBUG = 'debug';
    case INFO = 'info';
    case NOTICE = 'notice';
    case WARNING = 'warning';
    case ERROR = 'error';
    case CRITICAL = 'critical';
}
