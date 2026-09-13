<?php

namespace Tetranyble\Storage\Modules\Trust\Infrastructure;

use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;
use Tetranyble\Storage\Modules\Storage\Domain\Exceptions\InvalidStorageOperationException;
use Tetranyble\Storage\Modules\Trust\Domain\Contracts\QuarantineStoragePolicy;

class ConfiguredQuarantineStoragePolicy implements QuarantineStoragePolicy
{
    public function assertStorageSafe(Disk $disk): void
    {
        if (! (bool) config('tetranyble-storage.trust.virus_scanning.enabled', false)
            || ! (bool) config('tetranyble-storage.trust.quarantine_until_clean', true)
            || ! (bool) config('tetranyble-storage.trust.require_private_storage', true)) {
            return;
        }

        $allowed = array_map(
            static fn ($value): string => (string) $value,
            (array) config('tetranyble-storage.trust.quarantine_disks', [Disk::PRIVATE->value, Disk::S3_PRIVATE->value]),
        );

        if (! in_array($disk->value, $allowed, true)) {
            throw new InvalidStorageOperationException(sprintf(
                'Virus scanning with quarantine enabled requires a non-public storage disk. Disk [%s] is not allowed until a staged-release workflow is configured.',
                $disk->value,
            ));
        }
    }
}
