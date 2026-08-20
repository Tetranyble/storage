<?php

return [
    // A single package-wide default is used whenever no storage driver is supplied.
    'default_disk' => env('TETRANYBLE_STORAGE_DISK'),

    'transfer' => [
        'authorizer' => \Tetranyble\Storage\Domain\Media\AccessControlTransferAuthorizer::class,
    ],

    'models' => [
        'workspace' => \Tetranyble\Storage\Models\Workspace::class,
        'user' => \Tetranyble\Storage\Models\User::class,
        'folder' => \Tetranyble\Storage\Models\Folder::class,
        'media' => \Tetranyble\Storage\Models\Media::class,
        'media_share' => \Tetranyble\Storage\Models\MediaShare::class,
        'upload_session' => \Tetranyble\Storage\Models\UploadSession::class,
    ],
    'database' => [
        'tables' => [
            'users' => env('TETRANYBLE_STORAGE_USERS_TABLE'),
            'workspaces' => env('TETRANYBLE_STORAGE_WORKSPACES_TABLE'),
        ],
    ],
    'workspace' => [
        'resolver' => \Tetranyble\Storage\Workspace\AuthenticatedWorkspace::class,
        'guard' => null,
        'workspace_relation' => 'workspace',
        'workspace_foreign_key' => 'workspace_id',
        'resource_foreign_key' => 'workspace_id',
    ],
    'defaults' => [
        'profile' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Domain\FileSystem\Enums\Disk::PUBLIC->value,
        ],
        'image' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Domain\FileSystem\Enums\Disk::PUBLIC->value,
        ],
        'video' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Domain\FileSystem\Enums\Disk::PUBLIC->value,
        ],
    ],
    'image_metadata' => [
        'max_bytes' => 15 * 1024 * 1024,
    ],
    'thumbnails' => [
        'width' => 320,
        'height' => 240,
        'quality' => 80,
        'max_source_bytes' => 20 * 1024 * 1024,
    ],
    'cloud_drives' => [
        'google_drive' => [
            'client_id'     => env('GOOGLE_DRIVE_CLIENT_ID'),
            'client_secret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
            'redirect_uri'  => env('GOOGLE_DRIVE_REDIRECT_URI'),
        ],
        'onedrive' => [
            'client_id'     => env('ONEDRIVE_CLIENT_ID'),
            'client_secret' => env('ONEDRIVE_CLIENT_SECRET'),
            'redirect_uri'  => env('ONEDRIVE_REDIRECT_URI'),
            'tenant_id'     => env('ONEDRIVE_TENANT_ID', 'common'),
        ],
    ],

    'routes' => [
        // Disable every package HTTP endpoint while keeping its services available.
        'enabled' => env('TETRANYBLE_STORAGE_ROUTES_ENABLED', true),
        'prefix' => 'storage',
        'name' => 'tetranyble-storage.',
        'middleware' => ['web', 'auth'],
        'public_middleware' => ['web'],
        'controllers' => [
            'download' => \Tetranyble\Storage\Http\Controllers\DownloadController::class,
            'media' => \Tetranyble\Storage\Http\Controllers\MediaController::class,
            'chunked_upload' => \Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController::class,
            'library' => \Tetranyble\Storage\Http\Controllers\MediaLibraryController::class,
            'share' => \Tetranyble\Storage\Http\Controllers\MediaShareController::class,
            'transfer' => \Tetranyble\Storage\Http\Controllers\MediaTransferController::class,
        ],
    ],

    'remote' => [
        'max_size' => 50 * 1024 * 1024,
        'max_redirects' => 3,
        'allowed_schemes' => ['https', 'http'],
        'allowed_hosts' => [],
        'block_private_networks' => true,
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'video/mp4',
            'video/webm',
            'application/pdf',
        ],
    ],
];
