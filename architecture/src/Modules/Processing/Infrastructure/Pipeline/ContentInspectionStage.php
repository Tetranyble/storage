<?php

namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Pipeline;

use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaContentInspector;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\UnsafeMediaException;

final readonly class ContentInspectionStage implements MediaProcessingStage
{
    public function __construct(private MediaContentInspector $inspector) {}

    public function process(MediaProcessingContext $context): void
    {
        if (! (bool) config('tetranyble-storage.trust.content_inspection.enabled', true)) {
            return;
        }

        $inspection = $this->inspector->inspect($context->target);
        $context->media->forceFill([
            'detected_mime_type' => $inspection->detectedMimeType,
            'mime_type' => $context->media->mime_type ?: $inspection->detectedMimeType,
        ])->save();

        if (! $inspection->isCompatibleWith($context->target->mimeType?->value)) {
            throw new UnsafeMediaException(sprintf(
                'Declared MIME type [%s] does not match detected content [%s].',
                $context->target->mimeType?->value ?? 'unknown',
                $inspection->detectedMimeType ?: 'unknown',
            ));
        }
    }
}
