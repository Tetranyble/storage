<?php

namespace Tetranyble\Storage\Events;

use Illuminate\Database\Eloquent\Model;
use Tetranyble\Storage\Modules\Folder\Infrastructure\Persistence\Eloquent\Models\Folder;

class FolderCreated
{
    public function __construct(
        public readonly Folder $folder,
        public readonly ?Model $actor = null,
    ) {}
}
