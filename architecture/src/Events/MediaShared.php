<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Modules\Media\Infrastructure\Persistence\Eloquent\Models\Media;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

class MediaShared
{
    public function __construct(
        public readonly Media $media,
        public readonly MediaShare $share,
        public readonly ?Model $actor = null,
    ) {}
}
