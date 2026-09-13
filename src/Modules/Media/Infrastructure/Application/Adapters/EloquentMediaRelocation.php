<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Infrastructure\Application\Adapters;

use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Media\Application\Contracts\MediaRelocation;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Media\Infrastructure\Storage\Media\MediaRelocationService;

final class EloquentMediaRelocation implements MediaRelocation
{
    public function __construct(private readonly MediaRelocationService $relocation) {}

    public function rename(object $media, string $name, ?object $actor = null): object
    {
        return $this->relocation->rename($this->media($media), $name, $this->actor($actor));
    }

    public function move(object $media, object $targetFolder, ?object $actor = null): object
    {
        return $this->relocation->move($this->media($media), $this->folder($targetFolder), $this->actor($actor));
    }

    private function media(object $value): Media
    {
        if (! $value instanceof Media) {
            throw new InvalidArgumentException('Expected Media model.');
        }

        return $value;
    }

    private function folder(object $value): Folder
    {
        if (! $value instanceof Folder) {
            throw new InvalidArgumentException('Expected Folder model.');
        }

        return $value;
    }

    private function actor(?object $value): ?Model
    {
        if ($value !== null && ! $value instanceof Model) {
            throw new InvalidArgumentException('Expected actor model.');
        }

        return $value;
    }
}
