<?php

return [
    // A single package-wide default is used whenever no storage driver is supplied.
    'default_disk' => env('STORAGE_DISK'),

    'transfer' => [
        'authorizer' => \Tetranyble\Storage\Modules\Transfer\Application\AccessControlTransferAuthorizer::class,
    ],

    'models' => [
        // Host application integration points. Storage-owned entity models are
        // package-owned and intentionally not replaceable.
        'workspace' => \Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\Workspace::class,
        'user' => \Tetranyble\Storage\Modules\Workspace\Infrastructure\Persistence\Eloquent\Models\User::class,
    ],
    'database' => [
        'tables' => [
            'users' => env('STORAGE_USERS_TABLE'),
            'workspaces' => env('STORAGE_WORKSPACES_TABLE'),
        ],
    ],
    'activities' => [
        'enabled' => env('STORAGE_ACTIVITIES_ENABLED', false),
        'load_migrations' => env('STORAGE_ACTIVITY_MIGRATIONS', false),
    ],
    'observability' => [
        'enabled' => env('STORAGE_OBSERVABILITY_ENABLED', true),
        'log_records' => env('STORAGE_OBSERVABILITY_LOG', true),
        'dispatch_events' => env('STORAGE_OBSERVABILITY_EVENTS', true),
        'log_channel' => env('STORAGE_OBSERVABILITY_LOG_CHANNEL'),
        'health' => [
            // Leave empty to probe the default disk plus disks referenced by Media.
            'disks' => [],
            'probe_key' => '.tetranyble-storage-health-probe',
            'max_workspaces' => (int) env('STORAGE_HEALTH_MAX_WORKSPACES', 1000),
            'quota_drift_tolerance_bytes' => (int) env('STORAGE_HEALTH_QUOTA_DRIFT_TOLERANCE', 0),
            'orphan_warning_count' => (int) env('STORAGE_HEALTH_ORPHAN_WARNING', 1),
            'orphan_critical_count' => (int) env('STORAGE_HEALTH_ORPHAN_CRITICAL', 100),
            'stuck_upload_minutes' => (int) env('STORAGE_HEALTH_STUCK_UPLOAD_MINUTES', 30),
            'stuck_direct_upload_minutes' => (int) env('STORAGE_HEALTH_STUCK_DIRECT_UPLOAD_MINUTES', 30),
            'processing_backlog_minutes' => (int) env('STORAGE_HEALTH_PROCESSING_BACKLOG_MINUTES', 30),
        ],
    ],
    'workspace' => [
        'resolver' => \Tetranyble\Storage\Http\AuthenticatedWorkspace::class,
        'guard' => null,
        'workspace_relation' => 'workspace',
        'workspace_foreign_key' => 'workspace_id',
        'resource_foreign_key' => 'workspace_id',
    ],
    'defaults' => [
        'profile' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk::PUBLIC->value,
        ],
        'image' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk::PUBLIC->value,
        ],
        'video' => [
            'path' => null,
            'disk' => \Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk::PUBLIC->value,
        ],
    ],
    'placement' => [
        // Business-specific placement belongs in host configuration/policies,
        // not in MediaService/ResumableUploadService conditionals.
        'private_modules' => [],
        'root_modules' => [],
        'private_purposes' => [],
    ],

    'uploads' => [
        // Hard ceiling for a completed upload. Chunked sessions validate declared total size against this value.
        'max_size' => (int) env('STORAGE_UPLOAD_MAX_SIZE', 50 * 1024 * 1024),
        // Per-request ceiling for an individual chunk.
        'max_chunk_size' => (int) env('STORAGE_UPLOAD_MAX_CHUNK_SIZE', 10 * 1024 * 1024),
    ],
    'reads' => [
        // Protect accidental in-memory reads while keeping HTTP fetching out of FileSystem::get().
        'max_size' => (int) env('STORAGE_READ_MAX_SIZE', 50 * 1024 * 1024),
    ],
    'queries' => [
        // Cursor endpoints clamp page sizes here even when called outside HTTP.
        'max_per_page' => (int) env('STORAGE_QUERY_MAX_PER_PAGE', 200),
        'benchmark' => [
            'folders' => (int) env('STORAGE_BENCHMARK_FOLDERS', 500),
            'media' => (int) env('STORAGE_BENCHMARK_MEDIA', 5000),
            'grants' => (int) env('STORAGE_BENCHMARK_GRANTS', 500),
            'max_queries' => (int) env('STORAGE_BENCHMARK_MAX_QUERIES', 40),
        ],
    ],
    'direct_uploads' => [
        // Opt in only when an S3-compatible disk and its optional Flysystem/AWS SDK dependency are installed.
        'enabled' => env('STORAGE_DIRECT_UPLOADS_ENABLED', false),
        'fallback_to_server' => env('STORAGE_DIRECT_UPLOADS_FALLBACK', true),
        // Requiring a client digest lets finalization prove that the object registered as Media is the intended object.
        'require_sha256' => env('STORAGE_DIRECT_UPLOADS_REQUIRE_SHA256', true),
        'multipart_threshold' => (int) env('STORAGE_DIRECT_UPLOADS_MULTIPART_THRESHOLD', 32 * 1024 * 1024),
        'part_size' => (int) env('STORAGE_DIRECT_UPLOADS_PART_SIZE', 8 * 1024 * 1024),
        'initial_signed_parts' => (int) env('STORAGE_DIRECT_UPLOADS_INITIAL_PARTS', 5),
        'max_signed_parts_per_request' => (int) env('STORAGE_DIRECT_UPLOADS_MAX_SIGNED_PARTS', 100),
        'url_ttl_seconds' => (int) env('STORAGE_DIRECT_UPLOADS_URL_TTL', 900),
        'session_ttl_minutes' => (int) env('STORAGE_DIRECT_UPLOADS_SESSION_TTL', 60),
        // Multipart SHA-256 can require a streaming verification read when the provider reports only a composite checksum.
        'stream_checksum_fallback' => env('STORAGE_DIRECT_UPLOADS_STREAM_CHECKSUM', true),
        'max_stream_checksum_bytes' => (int) env('STORAGE_DIRECT_UPLOADS_MAX_STREAM_CHECKSUM', 1024 * 1024 * 1024),
    ],
    'images' => [
        // Reject extreme dimensions before GD expands compressed image data.
        'max_width' => (int) env('STORAGE_IMAGE_MAX_WIDTH', 12000),
        'max_height' => (int) env('STORAGE_IMAGE_MAX_HEIGHT', 12000),
        'max_pixels' => (int) env('STORAGE_IMAGE_MAX_PIXELS', 40_000_000),
    ],
    'derivatives' => [
        'max_source_bytes' => (int) env('STORAGE_DERIVATIVE_MAX_SOURCE_BYTES', 20 * 1024 * 1024),
        // Derivatives are rebuildable assets and are tracked separately from the source Media row.
        'thumbnail' => [
            'enabled' => env('STORAGE_DERIVATIVE_THUMBNAIL_ENABLED', true),
            'width' => (int) env('STORAGE_DERIVATIVE_THUMBNAIL_WIDTH', 320),
            'height' => (int) env('STORAGE_DERIVATIVE_THUMBNAIL_HEIGHT', 240),
            'quality' => (int) env('STORAGE_DERIVATIVE_THUMBNAIL_QUALITY', 80),
            'primary_format' => env('STORAGE_DERIVATIVE_THUMBNAIL_PRIMARY', 'jpeg'),
            'formats' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORAGE_DERIVATIVE_THUMBNAIL_FORMATS', 'jpeg'))))),
        ],
        'preview' => [
            'enabled' => env('STORAGE_DERIVATIVE_PREVIEW_ENABLED', false),
            'width' => (int) env('STORAGE_DERIVATIVE_PREVIEW_WIDTH', 1600),
            'height' => (int) env('STORAGE_DERIVATIVE_PREVIEW_HEIGHT', 1600),
            'quality' => (int) env('STORAGE_DERIVATIVE_PREVIEW_QUALITY', 82),
            'primary_format' => env('STORAGE_DERIVATIVE_PREVIEW_PRIMARY', 'webp'),
            'formats' => array_values(array_filter(array_map('trim', explode(',', (string) env('STORAGE_DERIVATIVE_PREVIEW_FORMATS', 'webp,jpeg'))))),
        ],
    ],
    'retention' => [
        // Destructive retention is opt-in. The command can always run in dry-run mode.
        'enabled' => env('STORAGE_RETENTION_ENABLED', false),
        'trash_days' => (int) env('STORAGE_RETENTION_TRASH_DAYS', 30),
        'temporary_grace_hours' => (int) env('STORAGE_RETENTION_TEMP_GRACE_HOURS', 0),
        'terminal_upload_session_days' => (int) env('STORAGE_RETENTION_UPLOAD_SESSION_DAYS', 7),
        'terminal_direct_upload_days' => (int) env('STORAGE_RETENTION_DIRECT_UPLOAD_DAYS', 7),
        'batch_size' => (int) env('STORAGE_RETENTION_BATCH_SIZE', 250),
    ],
    'bulk' => [
        'max_items' => (int) env('STORAGE_BULK_MAX_ITEMS', 100),
        // Permanent delete is deliberately disabled for HTTP bulk requests unless explicitly enabled.
        'allow_permanent_delete' => env('STORAGE_BULK_ALLOW_PERMANENT_DELETE', false),
    ],
    'processing' => [
        'enabled' => env('STORAGE_PROCESSING_ENABLED', true),
        // Dispatch every persisted Media automatically. Laravel's sync queue still
        // executes inline; production applications can select redis/database/etc.
        'auto_dispatch' => env('STORAGE_PROCESSING_AUTO_DISPATCH', true),
        // Primarily for deterministic host/testing scenarios that do not want a queue job.
        'inline' => env('STORAGE_PROCESSING_INLINE', false),
        'connection' => env('STORAGE_PROCESSING_CONNECTION'),
        'queue' => env('STORAGE_PROCESSING_QUEUE', 'media-processing'),
        'tries' => (int) env('STORAGE_PROCESSING_TRIES', 3),
        'timeout_seconds' => (int) env('STORAGE_PROCESSING_TIMEOUT', 60),
        'backoff' => [10, 60, 300],
        // Queue handoff is recoverable from the Media row itself. This lease avoids
        // duplicate enqueue storms while allowing a crashed handoff to be retried.
        'dispatch_backoff' => [10, 60, 300],
        'dispatch_lease_seconds' => (int) env('STORAGE_PROCESSING_DISPATCH_LEASE', 300),
        'stale_after_minutes' => (int) env('STORAGE_PROCESSING_STALE_AFTER', 15),
    ],
    'orphan_cleanup' => [
        'max_attempts' => (int) env('STORAGE_ORPHAN_MAX_ATTEMPTS', 10),
        'backoff' => [60, 300, 1800, 7200, 21600],
    ],
    'trust' => [
        // Scanning is opt-in because enabling it requires a scanner runtime such as ClamAV.
        'content_inspection' => [
            'enabled' => env('STORAGE_CONTENT_INSPECTION_ENABLED', true),
            'prefix_bytes' => (int) env('STORAGE_CONTENT_INSPECTION_PREFIX_BYTES', 65536),
        ],
        'virus_scanning' => [
            'enabled' => env('STORAGE_VIRUS_SCANNING_ENABLED', false),
            'scanner' => \Tetranyble\Storage\Modules\Trust\Infrastructure\ClamAvMediaScanner::class,
            'clamav_binary' => env('STORAGE_CLAMAV_BINARY', 'clamscan'),
            'timeout_seconds' => (int) env('STORAGE_SCAN_TIMEOUT', 30),
            'max_scan_bytes' => (int) env('STORAGE_SCAN_MAX_SIZE', 50 * 1024 * 1024),
        ],
        // When scanning is enabled, package-owned delivery paths remain locked until CLEAN.
        'quarantine_until_clean' => env('STORAGE_QUARANTINE_UNTIL_CLEAN', true),
        // Logical quarantine cannot protect a directly addressable public object.
        // Fail closed on public disks while scanning is enabled unless a host opts out.
        'require_private_storage' => env('STORAGE_QUARANTINE_REQUIRE_PRIVATE', true),
        'quarantine_disks' => [
            \Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk::PRIVATE->value,
            \Tetranyble\Storage\Modules\Storage\Domain\Enums\Disk::S3_PRIVATE->value,
        ],
        'allow_on_scan_failure' => env('STORAGE_ALLOW_ON_SCAN_FAILURE', false),
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
        'dropbox' => [
            'client_id'     => env('DROPBOX_CLIENT_ID'),
            'client_secret' => env('DROPBOX_CLIENT_SECRET'),
            'redirect_uri'  => env('DROPBOX_REDIRECT_URI'),
        ],
    ],

    'routes' => [
        // Disable every package HTTP endpoint while keeping its services available.
        'enabled' => env('STORAGE_ROUTES_ENABLED', false),
        'prefix' => 'storage',
        'name' => 'tetranyble-storage.',
        // Protected routes fail closed: removing auth requires an explicit opt-in below.
        'middleware' => ['web', 'auth'],
        'public_middleware' => ['web'],
        'allow_unauthenticated_protected_routes' => env('STORAGE_ALLOW_UNAUTHENTICATED_PROTECTED_ROUTES', false),
        'rate_limits' => [
            'enabled' => env('STORAGE_HTTP_RATE_LIMITS_ENABLED', true),
            'public_per_minute' => (int) env('STORAGE_PUBLIC_RATE_LIMIT', 30),
            'authenticated_per_minute' => (int) env('STORAGE_AUTHENTICATED_RATE_LIMIT', 240),
        ],
        'controllers' => [
            'download' => \Tetranyble\Storage\Http\Controllers\DownloadController::class,
            'media' => \Tetranyble\Storage\Http\Controllers\MediaController::class,
            'chunked_upload' => \Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController::class,
            'direct_upload' => \Tetranyble\Storage\Http\Controllers\DirectUploadController::class,
            'library' => \Tetranyble\Storage\Http\Controllers\MediaLibraryController::class,
            'bulk' => \Tetranyble\Storage\Http\Controllers\BulkMediaController::class,
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
