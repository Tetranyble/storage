<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Contracts;

use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

interface QuarantineStoragePolicy
{
    public function assertStorageSafe(Disk $disk): void;
}
