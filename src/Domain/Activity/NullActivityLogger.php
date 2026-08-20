<?php

namespace Tetranyble\Storage\Domain\Activity;

use Tetranyble\Storage\Contracts\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

class NullActivityLogger implements ActivityLogger
{
    public function log(
        Model $subject,
        string $type,
        string $description,
        ?Model $actor = null,
        array $meta = [],
        array $changes = [],
        ?int $workspaceId = null,
    ): void {}
}
