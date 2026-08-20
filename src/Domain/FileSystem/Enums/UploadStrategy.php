<?php

namespace Tetranyble\Storage\Domain\FileSystem\Enums;

enum UploadStrategy: string
{
    case SINGLE = 'single';
    case CHUNKED = 'chunked';
}
