<?php

namespace Tetranyble\Storage\Modules\CloudDrive\Domain\Exceptions;

use RuntimeException;
use Tetranyble\Storage\Modules\CloudDrive\Domain\Enums\CloudProvider;

final class MissingCloudProviderDependency extends RuntimeException
{
    /** @param list<string> $packages */
    public function __construct(
        public readonly CloudProvider $provider,
        public readonly array $packages,
    ) {
        parent::__construct(sprintf(
            '%s support requires the optional Composer package%s [%s]. Install %s before using this provider.',
            $provider->label(),
            count($packages) === 1 ? '' : 's',
            implode(', ', $packages),
            implode(' ', array_map(static fn (string $package): string => '"'.$package.'"', $packages)),
        ));
    }
}
