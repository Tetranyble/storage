<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Versioning\Application\Contracts\MediaVersioning;
use Tetranyble\Storage\Modules\Versioning\Infrastructure\Application\MediaVersioningService;

final class EloquentMediaVersioning implements MediaVersioning
{
    public function __construct(private readonly MediaVersioningService $versioning) {}

    public function versions(object $media): iterable
    {
        return $this->versioning->versions($this->media($media));
    }

    public function currentVersion(object $media): ?object
    {
        return $this->versioning->currentVersion($this->media($media));
    }

    public function activity(object $media): iterable
    {
        return $this->versioning->activity($this->media($media));
    }

    public function deleteVersion(object $workspace, object $version, object $actor): void
    {
        $this->versioning->deleteVersion(
            $this->model($workspace),
            $this->media($version),
            $this->model($actor),
        );
    }

    public function prepareContext(?object $replacedMedia, bool $supersede = true): array
    {
        return $this->versioning->prepareContext(
            $replacedMedia ? $this->media($replacedMedia) : null,
            $supersede,
        );
    }

    public function applyContext(object $media, array $context, bool $isCurrent = true): void
    {
        $this->versioning->applyContext($this->media($media), $context, $isCurrent);
    }

    public function ensureVersionSeed(object $media): string
    {
        return $this->versioning->ensureVersionSeed($this->media($media));
    }

    private function model(object $value): Model
    {
        if (! $value instanceof Model) {
            throw new InvalidArgumentException('Expected Eloquent model.');
        }

        return $value;
    }

    private function media(object $value): Media
    {
        if (! $value instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

        return $value;
    }
}
