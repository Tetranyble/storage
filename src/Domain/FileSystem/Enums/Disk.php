<?php

namespace Tetranyble\Storage\Domain\FileSystem\Enums;

enum Disk: string
{
    case PRIVATE = 'local';
    case PUBLIC = 'public';
    case S3PRIVATE = 's3-private';
    case S3PUBLIC = 's3-public';
    case CLOUDINARY = 'cloudinary';
    case GOOGLEDRIVE = 'googledrive';
    case YOUTUBE = 'youtube';
    case VIMEO = 'video';
    case FTP = 'FTP';

    public static function default(): self
    {
        $configDisk = config('tetranyble-storage.default_disk')
            ?: config('filesystems.default', 'local');

        return self::tryFrom($configDisk) ?? self::PRIVATE;
    }
}
