<?php

namespace Tetranyble\Storage\Domain\FileSystem\Enums;

enum UploadSessionStatus: string
{
    case PENDING = 'pending';
    case UPLOADING = 'uploading';
    case ASSEMBLING = 'assembling';
    case FINALIZED = 'finalized';
    case CONFLICTED = 'conflicted';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::FINALIZED,
            self::CONFLICTED,
            self::CANCELLED,
            self::EXPIRED,
        ], true);
    }

    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }
}
