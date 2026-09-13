<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Workspace\Application\ReadModel;

final readonly class ActivityView
{
    public function __construct(public array $data) {}

    public function toArray(): array
    {
        return $this->data;
    }
}
