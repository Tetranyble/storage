<?php

namespace Tetranyble\Storage\Modules\Activity\Application\Contracts;


interface ActivityLogger
{
    public function log(
        object $subject,
        string $type,
        string $description,
        ?object $actor = null,
        array $meta = [],
        array $changes = [],
        ?int $workspaceId = null,
    ): void;
}
