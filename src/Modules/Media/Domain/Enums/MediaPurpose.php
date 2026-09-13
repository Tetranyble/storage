<?php

namespace Tetranyble\Storage\Modules\Media\Domain\Enums;

enum MediaPurpose: string
{
    case GENERAL = 'GENERAL';
    case IMAGE = 'IMAGE';
    case VIDEO = 'VIDEO';
    case AUDIO = 'AUDIO';
    case DOCUMENT = 'DOCUMENT';
    case IMPORT = 'IMPORT';
    case PROFILE = 'PROFILE';
    case LOGO = 'LOGO';
    case BANNER = 'BANNER';
    case FAVICON = 'FAVICON';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }

    /** @return list<string> */
    public function allowedMimeTypes(): array
    {
        return match ($this) {
            self::IMAGE, self::PROFILE, self::LOGO, self::BANNER => [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/avif',
            ],
            self::FAVICON => [
                'image/jpeg',
                'image/png',
                'image/webp',
                'image/avif',
                'image/svg+xml',
                'image/x-icon',
                'image/vnd.microsoft.icon',
            ],
            self::VIDEO => [
                'video/mp4',
                'video/quicktime',
                'video/webm',
                'video/mpeg',
                'video/x-msvideo',
            ],
            self::AUDIO => [
                'audio/mpeg',
                'audio/mp4',
                'audio/ogg',
                'audio/wav',
                'audio/webm',
            ],
            self::DOCUMENT => [
                'application/pdf',
                'text/plain',
                'text/csv',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.ms-powerpoint',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            ],
            self::GENERAL, self::IMPORT => [],
        };
    }
}
