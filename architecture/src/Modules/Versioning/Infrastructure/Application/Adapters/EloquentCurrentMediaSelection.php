<?php

namespace Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\Adapters;

use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\CurrentMediaSelection;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\CurrentMediaSelectionService;

final class EloquentCurrentMediaSelection implements CurrentMediaSelection
{
    public function __construct(private readonly CurrentMediaSelectionService $selection) {}

    public function select(object $media): object
    {
        if (! $media instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

return $this->selection->select($media);
    }
}
