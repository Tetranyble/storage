<?php

namespace Tetranyble\Storage\Modules\Storage\Application\Contracts;

use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

interface StoragePlacementPolicy
{
    /**
     * Return a preferred disk for the upload, or null to use the package default.
     */
    public function preferredDisk(MediaUploadOptions $options): ?Disk;
}
