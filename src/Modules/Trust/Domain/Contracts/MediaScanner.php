<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Contracts;

use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanResult;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

interface MediaScanner
{
    public function scan(MediaScanTarget $target): MediaScanResult;
}
