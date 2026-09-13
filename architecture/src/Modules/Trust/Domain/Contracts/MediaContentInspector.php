<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Contracts;

use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaContentInspection;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

interface MediaContentInspector
{
    public function inspect(MediaScanTarget $target): MediaContentInspection;
}
