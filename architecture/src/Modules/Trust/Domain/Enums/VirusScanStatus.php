<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Enums;

enum VirusScanStatus: string
{
    case PENDING = 'pending';
    case SCANNING = 'scanning';
    case CLEAN = 'clean';
    case INFECTED = 'infected';
    case FAILED = 'failed';
    case SKIPPED = 'skipped';
}
