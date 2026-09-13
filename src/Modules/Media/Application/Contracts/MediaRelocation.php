<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Media\Application\Contracts;

interface MediaRelocation
{
    public function rename(object $media, string $name, ?object $actor = null): object;

    public function move(object $media, object $targetFolder, ?object $actor = null): object;
}
