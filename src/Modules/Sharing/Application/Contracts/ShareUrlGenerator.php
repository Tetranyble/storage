<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Sharing\Application\Contracts;

interface ShareUrlGenerator
{
    public function urlFor(object $share, bool $absolute = true): string;
}
