<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Upload\Application\Contracts;

interface UploadLimits
{
    public function maxUploadBytes(): int;

    public function maxChunkBytes(): int;
}
