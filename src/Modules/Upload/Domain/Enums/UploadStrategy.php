<?php

namespace Tetranyble\Storage\Modules\Upload\Domain\Enums;

enum UploadStrategy: string
{
    case SINGLE = 'single';
    case CHUNKED = 'chunked';
    case DIRECT = 'direct';
}
