<?php

namespace Tetranyble\Storage\Modules\Processing\Application;

use Tetranyble\Storage\Modules\Shared\Application\Contracts\ResourceState;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaDeliveryPolicy;

class MediaDeliveryGuard
{
    public function __construct(private readonly MediaDeliveryPolicy $policy, private readonly ResourceState $state) {}

    public function canDeliver(object $media): bool
    {
        return $this->policy->canDeliver($this->state->attribute($media, 'virus_scan_status'), $this->state->attribute($media, 'processing_status'));
    }

    public function assertDeliverable(object $media): void
    {
        $this->policy->assertDeliverable($this->state->attribute($media, 'virus_scan_status'), $this->state->attribute($media, 'processing_status'));
    }
}
