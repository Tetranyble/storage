<?php
namespace Tetranyble\Storage\Modules\Processing\Infrastructure\Application\Adapters;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Processing\Application\Contracts\MediaProcessing;
use Tetranyble\Storage\Modules\Processing\Infrastructure\Application\MediaProcessingDispatcher;
final class EloquentMediaProcessing implements MediaProcessing
{
    public function __construct(private readonly MediaProcessingDispatcher $dispatcher) {}
    public function dispatch(object $media): void
    { if (!$media instanceof Media) throw new InvalidArgumentException('Expected Media model.'); $this->dispatcher->dispatch($media); }
}
