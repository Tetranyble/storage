<?php

namespace Tetranyble\Storage\Modules\Trust\Infrastructure;

use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaScanner;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanResult;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

class NullMediaScanner implements MediaScanner
{
    public function scan(MediaScanTarget $target): MediaScanResult
    {
        return MediaScanResult::skipped();
    }
}
