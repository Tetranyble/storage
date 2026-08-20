<?php

namespace Tetranyble\Storage\Enums;

enum CloudProvider: string
{
    case GOOGLE_DRIVE = 'google_drive';
    case ONEDRIVE     = 'onedrive';
    case DROPBOX      = 'dropbox';
    case S3           = 's3';
    case AZURE_BLOB   = 'azure_blob';
    case GCS          = 'gcs';
    case CLOUDINARY   = 'cloudinary';
    case LOCAL        = 'local';

    public function label(): string
    {
        return match($this) {
            self::GOOGLE_DRIVE => 'Google Drive',
            self::ONEDRIVE     => 'Microsoft OneDrive',
            self::DROPBOX      => 'Dropbox',
            self::S3           => 'Amazon S3',
            self::AZURE_BLOB   => 'Azure Blob Storage',
            self::GCS          => 'Google Cloud Storage',
            self::CLOUDINARY   => 'Cloudinary',
            self::LOCAL        => 'Local Disk',
        };
    }

    public function supportsOAuth(): bool
    {
        return match($this) {
            self::GOOGLE_DRIVE, self::ONEDRIVE, self::DROPBOX => true,
            self::S3, self::AZURE_BLOB, self::GCS,
            self::CLOUDINARY, self::LOCAL                     => false,
        };
    }
}
