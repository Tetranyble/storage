<?php

namespace Tetranyble\Storage\Modules\Storage\Infrastructure\Policies;

use Tetranyble\Storage\Modules\Storage\Application\Contracts\StoragePlacementPolicy;
use Tetranyble\Storage\Modules\Storage\Application\DTO\MediaUploadOptions;
use Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk;

class ConfiguredStoragePlacementPolicy implements StoragePlacementPolicy
{
    public function preferredDisk(MediaUploadOptions $options): ?Disk
    {
        $privateModules = array_values(array_filter(
            (array) config('tetranyble-storage.placement.private_modules', []),
            'is_string',
        ));
        $privatePurposes = array_values(array_filter(
            (array) config('tetranyble-storage.placement.private_purposes', []),
            'is_string',
        ));

        if (in_array((string) $options->module, $privateModules, true)
            || in_array($options->purpose->value, $privatePurposes, true)) {
            return Disk::PRIVATE;
        }

        return null;
    }
}
