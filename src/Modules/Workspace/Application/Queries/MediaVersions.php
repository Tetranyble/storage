<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\Queries;

final readonly class MediaVersions
{
    public function __construct(public object $workspace, public object $media, public ?object $actor = null) {}
}
