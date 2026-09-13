<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Infrastructure\ReadModel\Eloquent\Support;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;

final class EloquentReadResources
{
    public function model(object $resource, string $label): Model
    {
        if (! $resource instanceof Model) {
            throw new InvalidArgumentException("Expected {$label} to be an Eloquent model.");
        }
        return $resource;
    }

    public function media(object $resource): Media
    {
        if (! $resource instanceof Media) {
            throw new InvalidArgumentException('Expected media to be a package Media model.');
        }
        return $resource;
    }
}
