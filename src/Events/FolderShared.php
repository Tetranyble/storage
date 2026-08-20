<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\Folder;
use Tetranyble\Storage\Models\MediaShare;

class FolderShared
{
    public function __construct(
        public readonly Folder $folder,
        public readonly MediaShare $share,
        public readonly ?Model $actor = null,
    ) {}
}
