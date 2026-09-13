<?php

namespace Tetranyble\Storage\Modules\Processing\Domain\Enums;

enum MediaProcessingStatus: string
{
    case PENDING = 'pending';
    case QUEUED = 'queued';
    case PROCESSING = 'processing';
    case READY = 'ready';
    case BLOCKED = 'blocked';
    case FAILED = 'failed';
}
