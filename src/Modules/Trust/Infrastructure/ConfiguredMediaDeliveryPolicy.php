<?php

namespace Tetranyble\Storage\Modules\Trust\Infrastructure;

use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaQuarantinedException;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\MediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;

class ConfiguredMediaDeliveryPolicy implements MediaDeliveryPolicy
{
    public function canDeliver(VirusScanStatus $scanStatus, MediaProcessingStatus $processingStatus): bool
    {
        if ($scanStatus === VirusScanStatus::INFECTED || $processingStatus === MediaProcessingStatus::BLOCKED) {
            return false;
        }

        $scanningEnabled = (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false);
        $quarantine = (bool) config('tetranyble-storage.trust.quarantine_until_clean', true);

        if (! $scanningEnabled || ! $quarantine) {
            return true;
        }

        if ($scanStatus === VirusScanStatus::FAILED) {
            return (bool) config('tetranyble-storage.trust.allow_on_scan_failure', false);
        }

        return in_array($scanStatus, [VirusScanStatus::CLEAN, VirusScanStatus::SKIPPED], true);
    }

    public function assertDeliverable(VirusScanStatus $scanStatus, MediaProcessingStatus $processingStatus): void
    {
        if ($this->canDeliver($scanStatus, $processingStatus)) {
            return;
        }

        $message = match ($scanStatus) {
            VirusScanStatus::INFECTED => 'Media was blocked because malware was detected.',
            VirusScanStatus::FAILED => 'Media remains quarantined because malware scanning failed.',
            VirusScanStatus::PENDING, VirusScanStatus::SCANNING => 'Media is still being scanned and remains quarantined.',
            default => 'Media is quarantined and cannot be delivered.',
        };

        throw new MediaQuarantinedException($message);
    }
}
