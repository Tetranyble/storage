<?php

namespace Tetranyble\Storage\Enums;

enum MediaStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case RUNNING = 'RUNNING';
    case READY = 'READY';
    case DONE = 'DONE';
    case REQUIRES_REVIEW = 'REQUIRES_REVIEW';
    case APPROVED = 'APPROVED';
    case REJECTED = 'REJECTED';
    case FAILED = 'FAILED';
    case BLOCKED = 'BLOCKED';
    case ARCHIVED = 'ARCHIVED';
}
