<?php

namespace Tetranyble\Storage\Modules\Remote\Domain\Contracts;

interface RemoteUrlValidator
{
    public function assertSafe(string $url): void;
}
