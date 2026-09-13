<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline;

final readonly class MediaProcessingPipeline
{
    /** @param list<MediaProcessingStage> $stages */
    public function __construct(private array $stages) {}

    public function process(MediaProcessingContext $context): void
    {
        foreach ($this->stages as $stage) {
            $stage->process($context);
        }
    }
}
