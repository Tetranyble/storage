<?php

namespace Tetranyble\Storage\Tests\Unit\Trust;

use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Trust\Domain\Exceptions\MediaQuarantinedException;
use Tetranyble\Storage\Modules\Processing\Domain\Enums\MediaProcessingStatus;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Trust\Domain\Enums\VirusScanStatus;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredMediaDeliveryPolicy;
use Tetranyble\Storage\Modules\Trust\Infrastructure\ConfiguredQuarantineStoragePolicy;
use Tetranyble\Storage\Tests\PackageTestCase;

class MediaDeliveryPolicyTest extends PackageTestCase
{
    public function test_pending_media_is_quarantined_when_scanning_is_enabled(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        config()->set('tetranyble-storage.trust.quarantine_until_clean', true);

        $policy = new ConfiguredMediaDeliveryPolicy();

        $this->assertFalse($policy->canDeliver(VirusScanStatus::PENDING, MediaProcessingStatus::QUEUED));
        $this->expectException(MediaQuarantinedException::class);
        $policy->assertDeliverable(VirusScanStatus::SCANNING, MediaProcessingStatus::PROCESSING);
    }

    public function test_clean_media_is_deliverable_when_scanning_is_enabled(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);

        $policy = new ConfiguredMediaDeliveryPolicy();

        $this->assertTrue($policy->canDeliver(VirusScanStatus::CLEAN, MediaProcessingStatus::READY));
    }

    public function test_infected_media_is_never_deliverable_even_when_scanning_is_disabled(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', false);

        $policy = new ConfiguredMediaDeliveryPolicy();

        $this->assertFalse($policy->canDeliver(VirusScanStatus::INFECTED, MediaProcessingStatus::BLOCKED));
    }

    public function test_scan_failure_is_fail_closed_by_default_and_can_be_explicitly_opened(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        config()->set('tetranyble-storage.trust.allow_on_scan_failure', false);
        $policy = new ConfiguredMediaDeliveryPolicy();

        $this->assertFalse($policy->canDeliver(VirusScanStatus::FAILED, MediaProcessingStatus::FAILED));

        config()->set('tetranyble-storage.trust.allow_on_scan_failure', true);
        $this->assertTrue($policy->canDeliver(VirusScanStatus::FAILED, MediaProcessingStatus::FAILED));
    }

    public function test_quarantine_requires_private_storage_when_enabled(): void
    {
        config()->set('tetranyble-storage.trust.virus_scanning.enabled', true);
        config()->set('tetranyble-storage.trust.quarantine_until_clean', true);
        config()->set('tetranyble-storage.trust.require_private_storage', true);

        $policy = new ConfiguredQuarantineStoragePolicy();
        $policy->assertStorageSafe(Disk::PRIVATE);
        $this->addToAssertionCount(1);

        $this->expectException(InvalidStorageOperationException::class);
        $policy->assertStorageSafe(Disk::PUBLIC);
    }
}
