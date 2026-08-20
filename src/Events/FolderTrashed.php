<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Models\Folder;

class FolderTrashed
{
    public function __construct(
        public readonly Folder $folder,
        public readonly ?Model $actor = null,
    ) {}
}
