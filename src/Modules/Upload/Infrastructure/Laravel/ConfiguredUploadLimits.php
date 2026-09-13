<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Infrastructure\Laravel;

use Tetranyble\Storage\Modules\Upload\Application\Contracts\UploadLimits;

final class ConfiguredUploadLimits implements UploadLimits
{
    public function maxUploadBytes(): int
    {
        return max(1, (int) config('tetranyble-storage.uploads.max_size', 50 * 1024 * 1024));
    }

    public function maxChunkBytes(): int
    {
        return max(1, (int) config('tetranyble-storage.uploads.max_chunk_size', 10 * 1024 * 1024));
    }
}
