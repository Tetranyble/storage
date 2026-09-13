<?php

namespace Tetranyble\Storage\Modules\Processing\Application;

use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;

class MediaDeliveryGuard
{
    public function __construct(private readonly MediaDeliveryPolicy $policy, private readonly ResourceState $state) {}

    public function canDeliver(object $media): bool
    {
        return $this->policy->canDeliver(
            $this->state->attribute($media, 'virus_scan_status', VirusScanStatus::PENDING),
            $this->state->attribute($media, 'processing_status', MediaProcessingStatus::PENDING),
        );
    }

    public function assertDeliverable(object $media): void
    {
        $this->policy->assertDeliverable(
            $this->state->attribute($media, 'virus_scan_status', VirusScanStatus::PENDING),
            $this->state->attribute($media, 'processing_status', MediaProcessingStatus::PENDING),
        );
    }
}
