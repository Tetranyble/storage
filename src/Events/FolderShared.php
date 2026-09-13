<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;
use Tetranyble\Storage\Modules\Sharing\Infrastructure\Persistence\Eloquent\Models\MediaShare;

class FolderShared
{
    public function __construct(
        public readonly Folder $folder,
        public readonly MediaShare $share,
        public readonly ?Model $actor = null,
    ) {}
}
