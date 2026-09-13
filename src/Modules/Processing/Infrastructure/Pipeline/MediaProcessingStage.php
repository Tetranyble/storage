<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline;

interface MediaProcessingStage
{
    public function process(MediaProcessingContext $context): void;
}
