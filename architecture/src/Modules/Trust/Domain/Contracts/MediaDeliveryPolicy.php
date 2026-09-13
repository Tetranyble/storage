<?php

namespace Tetranyble\Storage\Modules\Trust\Domain\Contracts;

use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;

interface MediaDeliveryPolicy
{
    public function canDeliver(
        VirusScanStatus $scanStatus,
        MediaProcessingStatus $processingStatus,
    ): bool;

    public function assertDeliverable(
        VirusScanStatus $scanStatus,
        MediaProcessingStatus $processingStatus,
    ): void;
}
