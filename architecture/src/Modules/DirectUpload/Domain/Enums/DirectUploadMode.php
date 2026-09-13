<?php

namespace Tetranyble\Storage\Modules\DirectUpload\Domain\Enums;

enum DirectUploadMode: string
{
    case SINGLE = 'single';
    case MULTIPART = 'multipart';
    case FALLBACK = 'fallback';
}
