<?php

namespace Tetranyble\Storage\Enums;

enum AccessScope: string
{
    case WORKSPACE = 'workspace';
    case RESTRICTED = 'restricted';

    public static function default(): self
    {
        return self::WORKSPACE;
    }
}
