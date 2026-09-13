<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Remote\Application\Contracts;

/**
 * Reads the stable identifier of an opaque host resource without exposing
 * the persistence technology to the Remote application layer.
 */
interface ResourceIdentity
{
    public function key(object $resource): int|string|null;
}
