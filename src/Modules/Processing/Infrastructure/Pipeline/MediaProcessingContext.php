<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline;

use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Trust\Domain\DTO\MediaScanTarget;

final readonly class MediaProcessingContext
{
    public function __construct(
        public Media $media,
        public MediaScanTarget $target,
    ) {}
}
