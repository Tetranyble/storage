<?php

namespace Tetranyble\Storage\Modules\Activity\Infrastructure;

use Tetranyble\Storage\Modules\Activity\Application\Contracts\ActivityLogger;

class NullActivityLogger implements ActivityLogger
{
    public function log(
        object $subject,
        string $type,
        string $description,
        ?object $actor = null,
        array $meta = [],
        array $changes = [],
        ?int $workspaceId = null,
    ): void {}
}
