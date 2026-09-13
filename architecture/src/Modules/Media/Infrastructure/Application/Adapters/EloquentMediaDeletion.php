<?php

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters;

use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaDeletion;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaDeletionService;

final class EloquentMediaDeletion implements MediaDeletion
{
    public function __construct(private readonly MediaDeletionService $deletion) {}

    public function delete(object $media): void
    {
        if (! $media instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        } $this->deletion->delete($media);
    }
}
