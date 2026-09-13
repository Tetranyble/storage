<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
class MediaPermanentlyDeleted
{
    public function __construct(
        public readonly int $mediaId,
        public readonly ?int $workspaceId,
        public readonly ?string $path,
        public readonly ?Model $actor = null,
    ) {}
}
