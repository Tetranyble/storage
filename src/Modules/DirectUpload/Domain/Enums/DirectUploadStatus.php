<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\Enums;

enum DirectUploadStatus: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case FINALIZING = 'finalizing';
    case FINALIZED = 'finalized';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case FAILED = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::FINALIZED, self::CANCELLED, self::EXPIRED, self::FAILED], true);
    }
}
