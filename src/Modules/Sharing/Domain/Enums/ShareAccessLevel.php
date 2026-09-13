<?php

declare(strict_types=1);

namespace Tetranyble\Storage\Modules\Sharing\Domain\Enums;

enum ShareAccessLevel: string
{
    case VIEW = 'view';
    case DOWNLOAD = 'download';
}
