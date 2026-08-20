<?php

namespace Tetranyble\Storage\Contracts;

interface RemoteUrlValidator
{
    public function assertSafe(string $url): void;
}
