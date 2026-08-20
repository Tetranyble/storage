<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\Media;

class MediaUploaded
{
    public function __construct(
        public readonly Media $media,
        public readonly ?Model $actor = null,
    ) {}
}
