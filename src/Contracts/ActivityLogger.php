<?php

namespace Tetranyble\Storage\Contracts;

use Tetranyble\Storage\Models\Activity;
use Illuminate\Database\Eloquent\Model;

interface ActivityLogger
{
    public function log(
        Model $subject,
        string $type,
        string $description,
        ?Model $actor = null,
        array $meta = [],
        array $changes = [],
        ?int $workspaceId = null,
    ): Activity;
}
