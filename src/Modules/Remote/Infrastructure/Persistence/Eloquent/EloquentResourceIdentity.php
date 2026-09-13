<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Remote\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Remote\Application\Contracts\ResourceIdentity;

final class EloquentResourceIdentity implements ResourceIdentity
{
    public function key(object $resource): int|string|null
    {
        if (! $resource instanceof Model) {
            throw new InvalidArgumentException('Remote resource handles must be Eloquent models at the Laravel adapter boundary.');
        }

        $key = $resource->getKey();

        return is_int($key) || is_string($key) ? $key : null;
    }
}
