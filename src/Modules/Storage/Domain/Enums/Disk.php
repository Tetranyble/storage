<?php

namespace Tetranyble\Storage\Modules\Storage\Domain\Enums;

enum Disk: string
{
    case PRIVATE = 'local';
    case PUBLIC = 'public';
    case S3_PRIVATE = 's3-private';
    case S3_PUBLIC = 's3-public';
    case CLOUDINARY = 'cloudinary';
    case GOOGLE_DRIVE = 'googledrive';
    case YOUTUBE = 'youtube';
    case VIMEO = 'vimeo';
    case FTP = 'ftp';

    public static function default(?string $configuredDisk = null): self
    {
        return self::tryFrom((string) $configuredDisk) ?? self::PRIVATE;
    }
}
