<?php

namespace Tetranyble\Storage\Modules\Media\Domain\Enums;

enum MediaDerivativeKind: string
{
    case THUMBNAIL = 'thumbnail';
    case PREVIEW = 'preview';
}
