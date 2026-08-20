# Tetranyble Storage

A modular, multi-workspace storage and media management package for Laravel. Works like Google Drive — workspaces get folders, versioned files, sharing links, access control, comments, activity logs, and the ability to connect external cloud drives (Google Drive, OneDrive, Dropbox, Amazon S3, Azure Blob, Google Cloud Storage, Cloudinary, and local disks).

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Configuration](#configuration)
- [Workspace](#workspace)
- [Workspace Requirements](#workspace-requirements)
- [Model Integration](#model-integration)
- [Facades](#facades)
- [Core Concepts](#core-concepts)
- [Folder Management](#folder-management)
- [File Upload & Management](#file-upload--management)
- [Downloading Files](#downloading-files)
- [Emailing Media](#emailing-media)
- [Access Control](#access-control)
- [Sharing](#sharing)
- [Comments](#comments)
- [Storage Quota](#storage-quota)
- [Connected Drives](#connected-drives)
  - [Google Drive](#google-drive)
  - [Microsoft OneDrive](#microsoft-onedrive)
  - [Dropbox](#dropbox)
  - [Amazon S3](#amazon-s3)
  - [Azure Blob Storage](#azure-blob-storage)
  - [Google Cloud Storage](#google-cloud-storage)
  - [Cloudinary](#cloudinary)
  - [Local Disk](#local-disk)
- [Cross-Drive Operations](#cross-drive-operations)
- [HTTP Routes](#http-routes)
- [Events](#events)
- [Extending the Package](#extending-the-package)
- [Testing](#testing)

---

## Requirements

| Dependency | Version |
|---|---|
| PHP | ^8.2 |
| Laravel | ^12.0 |
| `google/apiclient` | ^2.15 |
| `microsoft/microsoft-graph` | ^1.0 |
| `spatie/dropbox-api` | ^1.0 |
| `league/flysystem` | ^3.0 |
| `league/flysystem-aws-s3-v3` | ^3.0 |
| `league/flysystem-azure-blob-storage` | ^3.0 |
| `league/flysystem-google-cloud-storage` | ^3.0 |
| `cloudinary/cloudinary_php` | ^2.0 |

---

## Installation

```bash
composer require tetranyble/storage
```

Publish and run the core storage migrations:

```bash
php artisan vendor:publish --tag=tetranyble-storage-migrations
php artisan migrate
```

Package-backed activity logging is off by default. Only publish its migration when you want the package to own activity storage:

```bash
php artisan vendor:publish --tag=tetranyble-storage-activity-migrations
```

The package migrations no longer require a host `workspaces` table to exist first. `workspace_id` columns are still present, but their foreign-key constraint is intentionally left to the host application so single-workspace or non-workspace installs do not fail during `migrate`.

Publish the config file:

```bash
php artisan vendor:publish --tag=tetranyble-storage-config
```

---

## Configuration

`config/storage.php`:

```php
return [
    // Used whenever attach/upload/import does not provide a Disk explicitly.
    'default_disk' => env('STORAGE_DISK'),

    'transfer' => [
        // Replace this contract implementation to apply host roles or permissions.
        'authorizer' => \Tetranyble\Storage\Domain\Media\AccessControlTransferAuthorizer::class,
    ],

    // Swap in your own Eloquent models if needed
    'models' => [
        'workspace'      => \Tetranyble\Storage\Models\Workspace::class,
        'user'        => \Tetranyble\Storage\Models\User::class,
        'folder'      => \Tetranyble\Storage\Models\Folder::class,
        'media'       => \Tetranyble\Storage\Models\Media::class,
        'media_share' => \Tetranyble\Storage\Models\MediaShare::class,
        'upload_session' => \Tetranyble\Storage\Models\UploadSession::class,
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

    'workspace' => [
        'resolver' => \Tetranyble\Storage\Workspace\AuthenticatedWorkspace::class,
        'guard' => null,
        'workspace_relation' => 'workspace',
        'workspace_foreign_key' => 'workspace_id',
    ],

    // HTTP routes
    'routes' => [
        'enabled' => true,
        'prefix' => 'storage',
        'name' => 'tetranyble-storage.',
        'middleware' => ['web', 'auth'],
        'public_middleware' => ['web'],
        'controllers' => [
            'media' => \Tetranyble\Storage\Http\Controllers\MediaController::class,
            'chunked_upload' => \Tetranyble\Storage\Http\Controllers\ChunkedMediaUploadController::class,
            'library' => \Tetranyble\Storage\Http\Controllers\MediaLibraryController::class,
            'share' => \Tetranyble\Storage\Http\Controllers\MediaShareController::class,
            'transfer' => \Tetranyble\Storage\Http\Controllers\MediaTransferController::class,
        ],
    ],

    // OAuth credentials for cloud drive providers
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
            'tenant_id'        => env('ONEDRIVE_TENANT_ID', 'common'),
        ],
    ],
];
```

---

## Workspace

Every authenticated controller lookup is constrained by the workspace returned from `Tetranyble\Storage\Contracts\Workspace`. The default resolver reads the configured guard, workspace relation, and workspace foreign key from the authenticated user.

Bind a custom implementation when the host resolves workspaces from domains, middleware, or another workspace library. Service APIs accept host Eloquent workspace and actor models; they are not coupled to the package fixture classes.

### Workspace Requirements

`workspace_id` is nullable in the published migrations, but a resolved workspace is still required for most package features.

You must provide a resolvable workspace before using:

- all package HTTP routes
- media library and folder browsing
- folder creation, rename, move, trash, and restore flows
- collaborator grants and `WORKSPACE` access scope
- public share creation and share downloads
- storage quota and usage tracking
- connected drives and cross-drive operations
- storage-driver copy and move operations

The default resolver looks for actor and workspace context in this order:

- a user model implementing `Tetranyble\Storage\Contracts\StorageUser`
- a model implementing `Tetranyble\Storage\Contracts\WorkspaceSubject`
- the configured relation from `storage.workspace.workspace_relation`
- the configured actor foreign key from `storage.workspace.workspace_foreign_key`

If you want a no-config path, implement `StorageUser` on your actor model. The package trait `BelongsToStorageWorkspace` provides the required methods for the common belongs-to case.

The config fallback expects the authenticated actor to expose either:

- a `workspace()` relation, or
- a `workspace_id` attribute

Those fallback names are configurable in `storage.workspace.workspace_relation` and `storage.workspace.workspace_foreign_key`.

If your host application uses a different users table, set `storage.database.tables.users` before running the package migrations. If you also maintain a host `workspaces` table, point `storage.database.tables.workspaces` at it for model resolution; the package migrations no longer hard-require that table to exist.

`workspace_id` is optional only when you use the low-level media APIs without the package's route layer or workspace-scoped features. In practice, that means standalone uploads, model attachments, and resumable uploads can be created without a workspace if your host application wants global media instead of isolated workspace media.

---

## Model Integration

Add `InteractsWithMedia` to any Eloquent model that needs file behavior:

```php
use Tetranyble\Storage\Concerns\InteractsWithMedia;

class Loan extends Model
{
    use InteractsWithMedia;
}
```

The concern provides `media()`, `currentMedia()`, `setCurrentMediaItem()`, `mediaForPurpose()`, `uploadMediaFile()`, `replaceMediaFile()`, `attachMedia()`, `updateMediaMetadata()`, `trashMediaItem()`, `restoreMediaItem()`, and `deleteMediaItem()`. The legacy `Contracts\Mediable` trait remains as a deprecated compatibility alias.

`attachMedia()` accepts an optional `storageDriver`. If omitted, the package's single `default_disk` is used:

```php
$candidate = $user->attachMedia(
    'https://assets.example.com/avatar.png',
    purpose: MediaPurpose::PROFILE,
    makeCurrent: false,
    storageDriver: Disk::S3PRIVATE,
);

$user->setCurrentMediaItem($candidate);
```

---

## Facades

All major services are available as Laravel facades. They are registered automatically via package auto-discovery — no manual `config/app.php` entry needed.

| Facade | Resolves | Purpose |
|---|---|---|
| `TetranybleFileManager` | `WorkspaceFileManagerService` | Browse, upload, trash, restore, star files and folders |
| `TetranybleMediaUpload` | `MediaService` | Low-level upload API — attach files to Eloquent models |
| `TetranybleMediaVersioning` | `MediaVersioningService` | Version history, restore, delete old revisions |
| `TetranybleMediaMail` | `MediaMailService` | Build Laravel mail attachments, base64 payloads, and public email links |
| `TetranybleCloudDrive` | `ConnectedDriveService` | Connect, browse, and operate external cloud drives |
| `TetranybleStorageQuota` | `StorageService` | Quota checks and usage tracking |
| `TetranybleMediaSharing` | `MediaShareService` | Generate and revoke public share links |
| `TetranybleMediaAccess` | `ResourceAccessControl` | Grant / revoke / check collaborator access |

### Example usage

```php
use TetranybleFileManager;
use TetranybleMediaVersioning;
use TetranybleMediaAccess;
use Tetranyble\Storage\Enums\CollaboratorRole;

// Upload a file to a workspace's workspace
$media = TetranybleFileManager::uploadFile($workspace, $request->file('doc'), $folder, $user);

// List all versions of the uploaded file
$versions = TetranybleMediaVersioning::versions($media);

// Grant another user edit access
TetranybleMediaAccess::grant($workspace, $media, $colleague, CollaboratorRole::EDITOR, grantedBy: $user);
```

You can also resolve any service directly from the container — facades are just convenience wrappers:

```php
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;

$manager = app(WorkspaceFileManagerService::class);
```

---

## Core Concepts

| Concept | Description |
|---|---|
| **Workspace** | Isolated workspace with its own storage quota, folders, and drives |
| **Media** | A stored file — tracks path, disk, MIME type, size, version history, and access scope |
| **Folder** | A named directory within a workspace's workspace |
| **ConnectedDrive** | An external cloud storage account (Google Drive, S3, etc.) attached to a workspace |
| **AccessScope** | `WORKSPACE` (any member can view) or `RESTRICTED` (explicit collaborator grant required) |
| **CollaboratorRole** | `VIEWER`, `EDITOR`, or `OWNER` — controls what a collaborator can do on a resource |

All services are bound in the service container and can be resolved via dependency injection or `app()`.

---

## Folder Management

```php
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;

$manager = app(WorkspaceFileManagerService::class);

// List folders and files at the root of a workspace's workspace
$payload = $manager->indexPayload($workspace, relativePath: '', actor: $user);
// $payload['folders']['data']  → Collection of Folder models
// $payload['files']['data']    → Collection of Media models
// $payload['folders']['pagination'] → pagination metadata

// Create a folder
$folder = $manager->createFolder($workspace, name: 'Documents', actor: $user);

// Create a nested folder
$nested = $manager->createFolder($workspace, name: 'Invoices', actor: $user, parent: $folder);

// Rename
$manager->renameFolder($workspace, $folder, newName: 'Contracts', actor: $user);

// Trash (soft delete)
$manager->trashFolder($workspace, $folder, actor: $user);
```

---

## File Upload & Management

```php
use Tetranyble\Storage\Domain\Media\WorkspaceFileManagerService;
use Tetranyble\Storage\Domain\FileSystem\Enums\Disk;

$manager = app(WorkspaceFileManagerService::class);

// Upload from an HTTP request
$media = $manager->uploadFile(
    workspace:  $workspace,
    file:    $request->file('document'),
    folder:  $folder,
    actor:   $user,
    disk:    Disk::PRIVATE,     // optional, defaults to configured default
);

// Star / unstar a file (bookmark)
$manager->star($workspace, $media, $user);
$manager->unstar($workspace, $media, $user);

// Trash and restore
$manager->trashMedia($workspace, $media, $user);
$manager->restoreMedia($workspace, $media, $user);

// Permanently delete
$manager->permanentlyDeleteMedia($workspace, $media, $user);
```

### Versioning

Uploads default to `makeCurrent: true`. Pass `makeCurrent: false` to retain the existing profile, logo, or other purpose default. Selecting an item as current atomically clears all other current media for that model and purpose. `replaceExisting` remains independent and controls revision history. Version metadata fields (`current`, `version_group_uuid`, `version_number`, `previous_version_id`) are intentionally not mass-assignable.

```php
use Tetranyble\Storage\Domain\Media\MediaVersioningService;

$versioning = app(MediaVersioningService::class);

// List all versions of a file, newest first
$versions = $versioning->versions($media);
// Returns: Collection of Media — version 3, 2, 1 ...

// Get the currently active version
$current = $versioning->currentVersion($media);

// Upload a new revision (creates version N+1)
$newVersion = $manager->createRevision($workspace, $media, $request->file('document'), $user);

// Restore an older revision (copies the file, creates a new version record)
$restored = $manager->restoreVersion($workspace, $media, revision: $versions->last(), actor: $user);

// Permanently delete a non-current version
$versioning->deleteVersion($workspace, $oldVersion, actor: $user);

// Full audit trail for all versions in the group
$history = $versioning->activity($media);
```

> **Note:** You cannot delete the current version — restore a different revision first, then delete the old one.
>
> If package activities are disabled and you do not bind your own `ActivityFeed`, revision history returns an empty collection by design.

---

## Downloading Files

The package registers HTTP routes automatically. Authenticated users can download files they have access to.

### HTTP endpoints

| Method | URL | Description |
|---|---|---|
| `GET` | `/storage/media/{uuid}/download` | Download a single file |
| `POST` | `/storage/media/zip` | Download multiple files as a ZIP archive |

### ZIP request body

```json
{
    "items": ["uuid-1", "uuid-2", "uuid-3"],
    "name": "my-archive"
}
```

Files the actor cannot view are silently skipped. The response is streamed as `application/zip`.

### Programmatic download

```php
use Tetranyble\Storage\Domain\CloudDrive\DownloadService;

$downloader = app(DownloadService::class);

// Single file — returns an Illuminate Response
$response = $downloader->downloadMedia($workspace, $media, $actor);

// ZIP of multiple files
$result = $downloader->zipMedia($workspace, $mediaItems, $actor, archiveName: 'batch');
// $result['response'] → Response  (stream the ZIP)
// $result['zipped']   → int        (number of files included)
// $result['skipped']  → int        (number skipped due to permission)
return $result['response'];
```

---

## Emailing Media

The package supports two clean email delivery paths:

- direct Laravel attachments
- public share links for email bodies

### Direct Laravel attachments

`Media` implements Laravel's attachable contract, so you can return it directly from a mailable:

```php
use Illuminate\Mail\Mailable;
use Tetranyble\Storage\Models\Media;

class SendWorkspaceDocument extends Mailable
{
    public function __construct(private Media $media) {}

    public function attachments(): array
    {
        return [$this->media];
    }
}
```

For explicit control, use the mail service or facade:

```php
use TetranybleMediaMail;

$attachment = TetranybleMediaMail::attachment($media);
$inlineData = TetranybleMediaMail::base64Payload($media);
```

The attachment flow always resolves:

- filename
- MIME type via Laravel's `withMime(...)`
- storage-backed attachment when possible
- raw-data fallback for external media

### Public link payloads

If you want an email-safe public link instead of attaching bytes:

```php
use TetranybleMediaMail;

$payload = TetranybleMediaMail::publicLinkPayload($workspace, $media);

// $payload->url
// $payload->mime
// $payload->filename
// $payload->share
```

### Payload shape

Base64 payloads expose the fields most mail integrations need:

```php
[
    'type' => 'base64',
    'filename' => 'document.pdf',
    'mime' => 'application/pdf',
    'disposition' => 'attachment',
    'content' => '...',
]
```

URL payloads expose:

```php
[
    'type' => 'url',
    'filename' => 'document.pdf',
    'mime' => 'application/pdf',
    'disposition' => 'attachment',
    'url' => 'https://...',
]
```

---

## Access Control

Every `Media` or `Folder` has an `access_scope`:

- **`WORKSPACE`** — any authenticated member of the same workspace can view and download
- **`RESTRICTED`** — only collaborators with an explicit grant can access

```php
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use Tetranyble\Storage\Enums\CollaboratorRole;

$acl = app(ResourceAccessControl::class);

// Grant a collaborator role
$acl->grant($workspace, $media, $user, CollaboratorRole::VIEWER, grantedBy: $admin);
$acl->grant($workspace, $folder, $user, CollaboratorRole::EDITOR);

// Revoke access
$acl->revoke($workspace, $media, $user);

// Check access
if ($acl->canView($workspace, $media, $user)) { ... }
if ($acl->canEdit($workspace, $media, $user)) { ... }

// Throw 403 if the actor cannot view
$acl->authorizeView($workspace, $media, $user);
```

---

## Sharing

Generate shareable public links for files and folders.

```php
use Tetranyble\Storage\Domain\Media\MediaShareService;

$shares = app(MediaShareService::class);

// Share a file — returns a MediaShare with a unique token
$share = $shares->createForMedia(
    workspace:       $workspace,
    media:        $media,
    accessLevel:  'download',   // 'view' or 'download'
    ttlMinutes:   60 * 24 * 7, // 1 week; null = never expires
    maxDownloads: 10,           // null = unlimited
    password:     'secret123',  // null = no password
    createdBy:    $user->id,
);

// $share->token → share token (embed in a URL for public access)

// Share a folder
$share = $shares->createForFolder($workspace, $folder, accessLevel: 'view');

// Verify a share token before serving content
$share = $shares->resolveByToken($token);
if ($share && ! $share->isExpired() && ! $share->hasReachedDownloadsLimit()) {
    $share->incrementDownloads(); // call after serving
}
```

---

## Comments

Users can leave threaded comments on any `Media` or `Folder`.

```php
use Tetranyble\Storage\Domain\Media\CommentService;

$comments = app(CommentService::class);

// Add a top-level comment
$comment = $comments->addComment($workspace, $media, $user, body: 'Please review this.');

// Reply to a comment
$reply = $comments->addComment($workspace, $media, $user, body: 'Done!', parentComment: $comment);

// Edit
$comments->editComment($workspace, $comment, $user, newBody: 'Updated review notes.');

// Soft-delete
$comments->deleteComment($workspace, $comment, $user);

// List (with replies nested)
$thread = $comments->listComments($workspace, $media);
```

---

## Storage Quota

```php
use Tetranyble\Storage\Domain\FileSystem\StorageService;

$storage = app(StorageService::class);

// Get usage
$usage = $storage->usage($workspace);
// $usage->usedBytes    → bytes used
// $usage->quotaBytes   → total quota
// $usage->usedPercent  → 0–100

// Throw StorageQuotaExceededException if the upload would exceed the quota
$storage->assertCanStore($workspace, bytes: $fileSize);

// Adjust counters manually (e.g. after import)
$storage->increaseUsage($workspace, bytes: $fileSize);
$storage->decreaseUsage($workspace, bytes: $fileSize);

// Recompute from actual Media records (use as a scheduled reconciliation job)
$storage->recalculateUsage($workspace);
```

---

## Connected Drives

Workspaces can connect one or more external cloud drives. All drives implement the same `CloudAdapter` contract — browse, upload, download, copy, move, and delete work identically regardless of provider.

If multiple drives are connected, one is designated as the **default**. The default is used when no drive is specified in an operation.

### Google Drive

**OAuth flow:**

```php
use Tetranyble\Storage\Domain\CloudDrive\OAuthService;
use Tetranyble\Storage\Domain\CloudDrive\ConnectedDriveService;
use Tetranyble\Storage\Enums\CloudProvider;

$oauth = app(OAuthService::class);
$drives = app(ConnectedDriveService::class);

// 1. Redirect the user to Google's consent screen
$authUrl = $oauth->getAuthorizationUrl(CloudProvider::GOOGLE_DRIVE);
return redirect($authUrl);

// 2. After redirect back, exchange the code for tokens
$tokenData = $oauth->exchangeCode(CloudProvider::GOOGLE_DRIVE, $request->input('code'));

// 3. Persist the connected drive
$drive = $drives->connectOAuth($workspace, CloudProvider::GOOGLE_DRIVE, $tokenData, name: 'My Google Drive');
```

### Microsoft OneDrive

```php
// Same OAuth flow as Google Drive, different provider
$authUrl = $oauth->getAuthorizationUrl(CloudProvider::ONEDRIVE);

// After callback:
$tokenData = $oauth->exchangeCode(CloudProvider::ONEDRIVE, $request->input('code'));
$drive = $drives->connectOAuth($workspace, CloudProvider::ONEDRIVE, $tokenData, name: 'Work OneDrive');
```

### Dropbox

```php
// Dropbox also uses OAuth — same flow
$authUrl = $oauth->getAuthorizationUrl(CloudProvider::DROPBOX);

$tokenData = $oauth->exchangeCode(CloudProvider::DROPBOX, $request->input('code'));
$drive = $drives->connectOAuth($workspace, CloudProvider::DROPBOX, $tokenData, name: 'Dropbox');
```

### Amazon S3

```php
$drive = $drives->connectS3($workspace, credentials: [
    'bucket' => 'my-bucket',
    'key'    => env('AWS_ACCESS_KEY_ID'),
    'secret' => env('AWS_SECRET_ACCESS_KEY'),
    'region' => 'us-east-1',
    // Optional:
    'endpoint' => 'https://custom-endpoint',  // for S3-compatible providers (R2, Spaces, MinIO)
], name: 'Primary S3');
```

**S3-compatible providers** (Cloudflare R2, DigitalOcean Spaces, Backblaze B2, MinIO) use the same `connectS3()` — just pass the appropriate `endpoint`.

### Azure Blob Storage

```php
// Option A: full connection string
$drive = $drives->connectAzureBlob($workspace, credentials: [
    'connection_string' => env('AZURE_STORAGE_CONNECTION_STRING'),
    'container'         => 'my-container',
], name: 'Azure Files');

// Option B: account name + key
$drive = $drives->connectAzureBlob($workspace, credentials: [
    'account_name' => env('AZURE_STORAGE_ACCOUNT'),
    'account_key'  => env('AZURE_STORAGE_KEY'),
    'container'    => 'my-container',
], name: 'Azure Files');
```

### Google Cloud Storage

```php
// key_file is the decoded content of your service account JSON key
$keyFile = json_decode(file_get_contents(storage_path('gcs-key.json')), true);

$drive = $drives->connectGcs($workspace, credentials: [
    'key_file'    => $keyFile,
    'bucket'      => 'my-bucket',
    'path_prefix' => 'workspaces/acme/',  // optional — scope to a prefix within the bucket
], name: 'GCS Backup');
```

### Cloudinary

```php
$drive = $drives->connectCloudinary($workspace, credentials: [
    'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
    'api_key'    => env('CLOUDINARY_API_KEY'),
    'api_secret' => env('CLOUDINARY_API_SECRET'),
], name: 'Cloudinary Media');
```

### Local Disk

Connect any Laravel filesystem disk configured in `config/filesystems.php`.

```php
// Private local disk (files not web-accessible)
$drive = $drives->connectLocal($workspace, diskName: 'local', name: 'Local Private');

// Public disk (web-accessible via /storage)
$drive = $drives->connectLocal($workspace, diskName: 'public', name: 'Local Public');
```

---

### Managing Connected Drives

```php
// List all connected drives for a workspace
$connectedDrives = $drives->listConnected($workspace);

// Get the default drive
$default = $drives->getDefault($workspace);

// Change the default
$drives->setDefault($workspace, $drive, actor: $user);

// Disconnect (soft-deletes; the next oldest drive is promoted to default)
$drives->disconnect($workspace, $drive, actor: $user);
```

### Browsing a Drive

```php
// Browse the root of the default drive
$result = $drives->browseFolder($workspace);

// Browse a specific folder on a specific drive
$result = $drives->browseFolder($workspace, $drive, folderId: 'folder-id-or-path');

// $result['drive']   → drive metadata
// $result['folder']  → current folder ID
// $result['items']   → array of file/folder records
// $result['count']   → total items
```

### Importing & Exporting Files

```php
// Import a file from a cloud drive into the local Media library
$media = $drives->importFile($workspace, $drive, remoteFileId: 'remote-id', targetFolder: $folder, actor: $user);

// Export a local Media file to a cloud drive
$cloudFile = $drives->exportFile($workspace, $media, $drive, remoteFolderId: 'root');
```

---

## Cross-Drive Operations

Copy and move files between any two connected drives — or within the same drive using native server-side operations where supported.

```php
// Copy a file from one drive to another
$cloudFile = $drives->copyFile($workspace, from: $driveA, fileId: 'id', to: $driveB, targetFolderId: 'root', actor: $user);

// Move a file (cross-drive: copy then delete source)
$cloudFile = $drives->moveFile($workspace, from: $driveA, fileId: 'id', to: $driveB, targetFolderId: 'dest-folder', actor: $user);

// Copy an entire folder recursively
$result = $drives->copyFolder($workspace, from: $driveA, folderId: 'folder-id', to: $driveB);
// $result->filesCopied
// $result->foldersCreated
// $result->errors        → per-file errors (failed items are skipped, not aborted)

// Move an entire folder
$result = $drives->moveFolder($workspace, from: $driveA, folderId: 'folder-id', to: $driveB);
```

**Providers that support native server-side copy/move** (no download needed):
`S3`, `LocalDisk`, `AzureBlob`, `GCS`, `Dropbox`

---

### Downloading from a Drive

```php
use Tetranyble\Storage\Domain\CloudDrive\DownloadService;

$downloader = app(DownloadService::class);

// Single remote file
$response = $downloader->downloadFromDrive($workspace, $drive, remoteFileId: 'file-id');

// ZIP of multiple remote files (folders are recursively included)
$result = $downloader->zipFromDrive($workspace, $drive, remoteFileIds: ['id-1', 'id-2', 'folder-id'], archiveName: 'export');
return $result['response'];
```

---

## HTTP Routes

The package registers the following routes automatically. The prefix and middleware are configurable via `storage.routes`.

All authenticated package routes require the workspace resolver to return a current workspace. Route lookups are then constrained by `workspace_id`, so users must already be operating inside a resolved workspace context before calling these endpoints.

Disable every package route without disabling its services by setting:

```dotenv
STORAGE_ROUTES_ENABLED=false
```

Alternatively, set the published configuration directly:

```php
'routes' => [
    'enabled' => false,
],
```

This disables authenticated routes and public share routes. The route file also checks this setting when it has been published and loaded by the consuming application.

The same pattern exists for activity logging. Package-backed activity logging stays off unless you explicitly enable it:

```dotenv
STORAGE_ACTIVITIES_ENABLED=true
STORAGE_ACTIVITY_MIGRATIONS=true
```

Enable those only if you want the package to store and read activity from its own `activities` table. When left disabled, upload/share/version flows still work, but package-backed recent activity feeds and revision audit trails return empty results unless you bind your own activity contracts.

| Method | URL | Name | Description |
|---|---|---|---|
| `GET` | `/storage/media/{uuid}/download` | `tetranyble-storage.media.download` | Download a single local file |
| `POST` | `/storage/media/zip` | `tetranyble-storage.media.zip` | Download multiple files as ZIP |
| `POST` | `/storage/media/import-url` | `tetranyble-storage.media.import-url` | Download a validated URL into workspace storage |
| `POST` | `/storage/media/{uuid}/current` | `tetranyble-storage.media.current` | Select model media as current/default |
| `POST` | `/storage/media/{uuid}/copy-storage` | `tetranyble-storage.media.storage.copy` | Copy media to another Flysystem disk |
| `POST` | `/storage/media/{uuid}/move-storage` | `tetranyble-storage.media.storage.move` | Move media to another Flysystem disk |
| `POST` | `/storage/drives/{uuid}/default` | `tetranyble-storage.drives.default` | Select the workspace's only default connected drive |
| `POST` | `/storage/drives/{uuid}/files/copy` | `tetranyble-storage.drives.files.copy` | Copy a connected-drive file |
| `POST` | `/storage/drives/{uuid}/files/move` | `tetranyble-storage.drives.files.move` | Move a connected-drive file |

To publish the routes file and customise them:

```bash
php artisan vendor:publish --tag=tetranyble-storage-routes
```

---

## Events

Listen to these events in your application:

| Event | Fired when |
|---|---|
| `MediaUploaded` | A file is successfully uploaded |
| `MediaTrashed` | A file is moved to trash |
| `MediaRestored` | A file is restored from trash |
| `MediaPermanentlyDeleted` | A file is permanently deleted |
| `MediaShared` | A share link is created for a file |
| `FolderCreated` | A new folder is created |
| `FolderTrashed` | A folder is trashed |
| `FolderShared` | A share link is created for a folder |
| `DriveConnected` | A cloud drive is connected to a workspace |
| `DriveDisconnected` | A cloud drive is disconnected |

```php
// Example listener
use Tetranyble\Storage\Events\MediaUploaded;

Event::listen(MediaUploaded::class, function (MediaUploaded $event) {
    // $event->media   → the Media model
    // $event->workspace  → the Workspace model
    // $event->actor   → the User who uploaded (may be null)
});
```

---

## Extending the Package

### Custom User / Workspace models

You can wire the package to your own user and workspace models in the same style as packages like `jwt-auth`: implement the contract on your host model, then optionally point the package config at your custom classes and tables.

### Host model quick start

Update your user model so the package can resolve the current workspace without extra relation config:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Tetranyble\Storage\Concerns\BelongsToStorageWorkspace;
use Tetranyble\Storage\Contracts\StorageUser;

class User extends Authenticatable implements StorageUser
{
    use BelongsToStorageWorkspace;

    // Your existing traits, casts, fillables, JWTSubject implementation, etc.
}
```

The trait gives you the two required interface methods plus a `storageWorkspace()` relation. If you prefer your own relation name, implement the interface manually instead of using the trait.

Manual implementation example:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Tetranyble\Storage\Contracts\StorageUser;

class User extends Authenticatable implements StorageUser
{
    public function tenant()
    {
        return $this->belongsTo(Organisation::class, 'organisation_id');
    }

    public function getStorageUserIdentifier(): int|string|null
    {
        return $this->getKey();
    }

    public function getStorageWorkspace(): ?Model
    {
        return $this->tenant;
    }

    public function getStorageWorkspaceIdentifier(): int|string|null
    {
        return $this->organisation_id;
    }
}
```

Point the package to your host models and tables:

```php
// config/storage.php
'models' => [
    'workspace' => \App\Models\Organisation::class,
    'user' => \App\Models\User::class, // optional if Laravel auth already uses this model
],

'database' => [
    'tables' => [
        'users' => 'users',
        'workspaces' => 'organisations',
    ],
],
```

Notes:

- `models.workspace` tells the package which Eloquent class represents your workspace.
- `models.user` is optional in most Laravel apps. If omitted, the package falls back to the user model configured on your active auth provider.
- `database.tables.*` is used by the published package migrations when your host tables are not named `users` and `workspaces`.
- Package-side storage tables still use the package’s own foreign-key conventions like `workspace_id` and `user_id`.
- If you do not implement `WorkspaceSubject`, the resolver falls back to `workspace.workspace_relation` and `workspace.workspace_foreign_key`.

If you prefer config over the interface, you can still use your own relation method:

```php
public function tenant()
{
    return $this->belongsTo(Organisation::class, 'organisation_id');
}
```

Then set:

```php
'workspace' => [
    'workspace_relation' => 'tenant',
    'workspace_foreign_key' => 'organisation_id',
],
```

### Custom Access Control

Bind your own implementation of `ResourceAccessControl`:

```php
// AppServiceProvider
use Tetranyble\Storage\Contracts\ResourceAccessControl;
use App\Services\MyAccessControlService;

$this->app->bind(ResourceAccessControl::class, MyAccessControlService::class);
```

### Custom Activity Logging

If you want to store activity in your application's own log system, bind both contracts:

```php
use Tetranyble\Storage\Contracts\ActivityFeed;
use Tetranyble\Storage\Contracts\ActivityLogger;
use App\Services\MyActivityFeed;
use App\Services\MyActivityLogger;

$this->app->bind(ActivityFeed::class, MyActivityFeed::class);
$this->app->bind(ActivityLogger::class, MyActivityLogger::class);
```

`ActivityLogger` handles writes. `ActivityFeed` handles reads for:

- `WorkspaceFileManagerService::recentPayload()`
- `WorkspaceFileManagerService::activityPayload()`
- `MediaVersioningService::activity()`

If you only disable package activities and do not bind replacements, those reads intentionally return empty collections instead of querying the package `activities` table.

### Adding a new cloud provider

1. Add a case to `Tetranyble\Storage\Enums\CloudProvider`
2. Implement `Tetranyble\Storage\Domain\CloudDrive\Contracts\CloudAdapter`
3. Optionally implement `SupportsSameDriveOperations` for native server-side copy/move
4. Add a `buildXxxAdapter()` method and a `CloudProvider::XXX` arm in `ConnectedDriveService::adapterFor()`

---

## Testing

The package ships with an Orchestra Testbench harness.

```bash
cd packages/Tetranyble/Storage
./vendor/bin/phpunit
```

In your application tests, fake events to avoid real side effects:

```php
use Illuminate\Support\Facades\Event;
use Tetranyble\Storage\Events\MediaUploaded;

Event::fake();

// ... perform upload ...

Event::assertDispatched(MediaUploaded::class, function ($event) use ($media) {
    return $event->media->id === $media->id;
});
```

Fake the filesystem to avoid real disk I/O:

```php
use Illuminate\Support\Facades\Storage;

Storage::fake('local');
Storage::fake('s3');
```
