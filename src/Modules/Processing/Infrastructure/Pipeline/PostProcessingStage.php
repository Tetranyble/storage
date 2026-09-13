<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline;

use Tetranyble\Storage\Modules\Processing\Infrastructure\ImageProcessing\MediaPostProcessor;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;

final readonly class PostProcessingStage implements MediaProcessingStage
{
    public function __construct(private MediaPostProcessor $postProcessor) {}

    public function process(MediaProcessingContext $context): void
    {
        $this->postProcessor->process($context->media, new MediaUploadOptions);
    }
}
