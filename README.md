# Tetranyble Storage

[![CI](https://github.com/Tetranyble/storage/actions/workflows/ci.yml/badge.svg)](https://github.com/Tetranyble/storage/actions/workflows/ci.yml)
[![Latest Packagist Version](https://img.shields.io/packagist/v/tetranyble/storage.svg)](https://packagist.org/packages/tetranyble/storage)
[![Total Downloads](https://img.shields.io/packagist/dt/tetranyble/storage.svg)](https://packagist.org/packages/tetranyble/storage)

Production-oriented storage, media-library, upload, sharing, cloud-drive, processing, and file-management infrastructure for Laravel 12 and 13.

CI is verified by GitHub Actions and a verification-only CircleCI fallback; stable tags/releases remain gated through GitHub. See [`docs/CI_CD.md`](docs/CI_CD.md) and [`docs/CIRCLECI.md`](docs/CIRCLECI.md).

The package is designed around one rule: **application/domain rules decide; storage providers and Laravel adapters implement them**. It supports workspace isolation, ACL-aware queries, quota accounting, resumable and direct uploads, media processing/quarantine, derivative assets, retention, bulk operations, and operational health checks.

## Requirements

- PHP `^8.2`
- Laravel / Illuminate `^12.0 | ^13.0`
- `ext-fileinfo`
- GD is optional but required for image derivatives
- Provider SDKs are optional and installed only when that provider is used

## Install

```bash
composer require tetranyble/storage
php artisan vendor:publish --tag=tetranyble-storage-config
php artisan migrate
```

Package HTTP routes are disabled by default. Enable them explicitly:

```env
STORAGE_ROUTES_ENABLED=true
```

If activity logging is required:

```env
STORAGE_ACTIVITIES_ENABLED=true
STORAGE_ACTIVITY_MIGRATIONS=true
```

then run migrations again.

## Architecture

```text
src/
├── Modules/
│   └── <Capability>/
│       ├── Domain/          framework-free rules, value objects and lifecycle models
│       ├── Application/     use cases and orchestration
│       └── Infrastructure/  Eloquent, filesystem and provider adapters
├── Http/                    Laravel HTTP adapter
├── Console/                 Laravel CLI adapter
├── Facades/
├── Events/
└── StorageServiceProvider.php
```

The package uses **modular Hexagonal architecture with selective tactical DDD**. Dependency direction and capability ownership are enforced by `composer run architecture`. Domain code has zero Laravel/Eloquent/Symfony HTTP dependencies. Lifecycle-heavy behavior such as direct/resumable uploads, sharing access and version-group allocation is exercised by a dependency-free state-machine gate.

See [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md), [`docs/LIFECYCLE_STATE_MACHINES.md`](docs/LIFECYCLE_STATE_MACHINES.md), [`docs/ADAPTER_ISOLATION.md`](docs/ADAPTER_ISOLATION.md), and [`docs/PROVIDER_STRATEGY_REGISTRY.md`](docs/PROVIDER_STRATEGY_REGISTRY.md).

## Host model integration

Storage-owned records are package-owned. The host integration points are only the user and workspace models.

```php
'models' => [
    'workspace' => App\Models\Workspace::class,
    'user' => App\Models\User::class,
],
```

For the common belongs-to workspace model:

```php
use Tetranyble\Storage\Concerns\BelongsToStorageWorkspace;
use Tetranyble\Storage\Modules\Workspace\Application\Contracts\StorageUser;

class User extends Authenticatable implements StorageUser
{
    use BelongsToStorageWorkspace;
}
```

Package persistence currently requires integer/BIGINT host user/workspace keys.

## Model media relationships

Use only the capabilities a model needs:

```php
use Tetranyble\Storage\Concerns\HasMedia;
use Tetranyble\Storage\Concerns\ManipulatesMedia;

class Project extends Model
{
    use HasMedia;
    use ManipulatesMedia;
}
```

`HasMedia` provides relationships/read helpers. `ManipulatesMedia` provides convenient upload/attach/metadata/lifecycle operations.

## Uploads

The configured upload ceiling is enforced below HTTP, so controller, job, service, remote-import, connected-drive, resumable, and direct-upload paths cannot bypass it.

```env
STORAGE_UPLOAD_MAX_SIZE=52428800
STORAGE_UPLOAD_MAX_CHUNK_SIZE=10485760
```

### Canonical application upload

```php
use Tetranyble\Storage\Http\Adapters\LaravelIncomingFile;
use Tetranyble\Storage\Modules\Media\Application\UploadMedia;

$media = app(UploadMedia::class)->uploadLibraryFiles(
    workspace: $workspace,
    uploadedFiles: [LaravelIncomingFile::fromUploadedFile($request->file('document'))],
    folderId: $folder?->id,
    actor: $user,
)->first();
```

### Resumable uploads

The resumable flow provides serialized chunk writes, single-active-session identifiers, exact declared-size enforcement, finalization locking, and failure compensation.

HTTP routes:

```text
POST   /storage/uploads
GET    /storage/uploads/{uploadSession}
PUT    /storage/uploads/{uploadSession}/chunks/{chunk}
POST   /storage/uploads/{uploadSession}/finalize
DELETE /storage/uploads/{uploadSession}
```

### Direct S3-compatible uploads

Direct uploads are optional. Install the S3 adapter:

```bash
composer require league/flysystem-aws-s3-v3:^3.28
```

Enable:

```env
STORAGE_DIRECT_UPLOADS_ENABLED=true
STORAGE_DIRECT_UPLOADS_REQUIRE_SHA256=true
```

The flow reserves quota before transfer, signs single PUT or multipart requests, verifies exact provider size and full-file SHA-256, finalizes idempotently into the canonical `Media` lifecycle, and releases quota on terminal failure/cancellation/expiry.

Unsupported disks can fall back to the normal server-mediated uploader.

## Storage quota

Quota changes use database-level atomic updates. Active direct-upload reservations are included in authoritative usage.

Useful operations:

```bash
php artisan storage:reconcile-usage
php artisan storage:health --json
```

`storage:health` is read-only; reconciliation is always explicit.

## ACL and large-workspace queries

Visibility is pushed into SQL before pagination. Restricted ancestors, folder grants, media grants, workspace access, stars, and activity filtering share the same visibility model.

High-volume global query surfaces use cursor pagination only:

```text
GET /storage/library/search?query=report&per_page=50
GET /storage/library/recent?per_page=25
GET /storage/library/activity?per_page=50
```

Search returns independent folder and file cursors. Query page size is clamped below HTTP with:

```env
STORAGE_QUERY_MAX_PER_PAGE=200
```

An opt-in query benchmark is included:

```bash
composer benchmark:queries
```

It reports query count, elapsed time, memory, and query plans against a synthetic ACL tree. Release CI runs the benchmark on PostgreSQL and MySQL and enables lazy-loading prevention to catch N+1 regressions.

## Media trust and processing

Stored bytes are inspected with `fileinfo` before derivatives are generated. High-confidence MIME mismatches fail closed.

Processing can run after commit through a queue:

```env
STORAGE_PROCESSING_ENABLED=true
STORAGE_PROCESSING_AUTO_DISPATCH=true
STORAGE_PROCESSING_QUEUE=media-processing
STORAGE_PROCESSING_TIMEOUT=60
STORAGE_PROCESSING_DISPATCH_LEASE=300
```

The `media` row is the durable processing intent: queue handoff uses a lease and bounded retry timing, so a crash before/after dispatch can be recovered without a second generic outbox table. The processing pipeline is ordered **content inspection → malware scan → derivatives**, and derivatives never run after an unsafe/failed scan.

Recover pending work, stale queue handoffs, and stale worker leases (use `--retry-failed` for terminal worker failures):

```bash
php artisan storage:process-media --limit=500
php artisan storage:process-media --limit=500 --retry-failed
```

### Malware scanning

Scanning is optional:

```env
STORAGE_VIRUS_SCANNING_ENABLED=true
STORAGE_CLAMAV_BINARY=clamscan
STORAGE_SCAN_TIMEOUT=30
```

When quarantine is enabled, package-owned download/share/email/export paths remain blocked until policy permits delivery. Private storage is required by default while malware quarantine is active.

## First-class derivatives

Thumbnails and previews are stored as `media_derivatives` rows. A derivative owns its own:

- kind and variant
- format and MIME type
- disk/path
- byte size
- dimensions
- SHA-256
- primary selection
- generation metadata

There is no `thumbnail_path` mirror on `media`.

Derivative object keys are content-addressed. Replacement writes a new object, commits metadata/primary selection under the parent Media lock, then retires the old object. A database failure leaves the previous derivative intact.

Configuration example:

```env
STORAGE_DERIVATIVE_THUMBNAIL_ENABLED=true
STORAGE_DERIVATIVE_THUMBNAIL_FORMATS=jpeg,webp,avif
STORAGE_DERIVATIVE_THUMBNAIL_PRIMARY=webp
STORAGE_DERIVATIVE_PREVIEW_ENABLED=true
STORAGE_DERIVATIVE_PREVIEW_FORMATS=webp,jpeg
STORAGE_DERIVATIVE_PREVIEW_PRIMARY=webp
```

JPEG EXIF orientation is normalized before resize. GD WebP/AVIF output is used when the runtime supports it. Image dimensions/pixel count are validated before GD expands compressed image data.

## Storage lifecycle and orphan recovery

Physical object storage cannot participate in the SQL transaction, so mutation flows use compensation and durable cleanup intents.

```env
STORAGE_ORPHAN_MAX_ATTEMPTS=10
```

```bash
php artisan storage:cleanup-orphans
php artisan storage:cleanup-orphans --retry-abandoned  # operator-directed retry after max attempts
php artisan storage:cleanup-direct-uploads --limit=500
```

Uploads clean failed objects and release quota. Permanent deletion commits database/quota truth with durable cleanup records, then removes physical objects. Rename/move operations use copy → database commit → retire old object. Orphan cleanup uses bounded backoff and marks exhausted records `abandoned_at`; health reports abandoned cleanup as critical instead of retrying a poison object forever.

## Retention

Retention is explicit and dry-run by default:

```bash
php artisan storage:retention
php artisan storage:retention --workspace=42
```

Destructive execution requires both configuration and `--apply`:

```env
STORAGE_RETENTION_ENABLED=true
STORAGE_RETENTION_TRASH_DAYS=30
STORAGE_RETENTION_UPLOAD_SESSION_DAYS=7
STORAGE_RETENTION_DIRECT_UPLOAD_DAYS=7
```

```bash
php artisan storage:retention --apply
```

Retention uses the same permanent-deletion lifecycle as normal media deletion and includes derivative quota/object cleanup.

## Bulk operations

Bulk trash, restore, move, and optional permanent delete reuse the canonical single-item use cases. They do not bypass ACL, lifecycle compensation, events, or quota accounting.

```env
STORAGE_BULK_MAX_ITEMS=100
STORAGE_BULK_ALLOW_PERMANENT_DELETE=false
```

HTTP routes:

```text
POST /storage/library/bulk/trash
POST /storage/library/bulk/restore
POST /storage/library/bulk/move
POST /storage/library/bulk/delete
```

Permanent bulk delete is hidden unless explicitly enabled.

## Sharing

Share downloads enforce expiry, password, access level, and download ceiling. Slot consumption is an atomic database mutation, so concurrent requests cannot both consume the last available download.

## Cloud drives

Supported integrations include local, Google Drive, OneDrive, Dropbox, S3-compatible storage, Azure Blob, Google Cloud Storage, and Cloudinary. Provider dependencies remain optional. Adapter construction, dependency requirements and credential validation are owned by registered `CloudProviderStrategy` implementations; `ConnectedDriveService` no longer contains a provider factory/switch.

Examples:

```bash
composer require google/apiclient:^2.15
composer require spatie/dropbox-api:^1.0
composer require league/flysystem-aws-s3-v3:^3.28
composer require azure-oss/storage-blob-flysystem:^2.2
```

OneDrive uses Microsoft Graph HTTP directly rather than requiring the generated Graph PHP SDK. The `CloudProviderRegistry` is container-managed and intentionally supports strategy replacement/registration for host-specific provider implementations.

## Observability and health

`StorageTelemetry` is provider-neutral. The Laravel implementation emits structured records through logging and `StorageTelemetryRecorded`. Sensitive values including credentials, tokens, object paths/keys, and signed URLs are stripped.

```bash
php artisan storage:health
php artisan storage:health --workspace=42
php artisan storage:health --json
php artisan storage:health --strict
```

Health checks cover database/storage connectivity, quota drift, orphan backlog, resumable/direct-upload health, processing backlog, and connected-drive status.

See [`docs/OPERATIONS.md`](docs/OPERATIONS.md).

## Routes

Routes are disabled by default. When enabled, package routes are mounted under `/storage` and protected by configured middleware. Public share delivery uses the separate public middleware configuration.

Controllers are configurable in `tetranyble-storage.routes.controllers` if a host needs custom HTTP adapters while keeping the application services.

## Facades

Available convenience facades:

| Facade alias | Service |
|---|---|
| `TetranybleMediaUpload` | `MediaService` |
| `TetranybleMediaVersioning` | `MediaVersioningService` |
| `TetranybleMediaMail` | `MediaMailService` |
| `TetranybleCloudDrive` | `ConnectedDriveService` |
| `TetranybleStorageQuota` | `StorageService` |
| `TetranybleMediaSharing` | `MediaShareService` |
| `TetranybleMediaAccess` | `ResourceAccessControl` |

Application use cases are preferred for business flows; facades are convenience access to lower-level package capabilities.

## Testing and quality gates

```bash
composer install
composer run architecture
composer run analyse
composer test
composer run format:check
composer run verify
composer run production:gate

Architecture rule: Application → Infrastructure references are forbidden. Focused use cases depend on capability-shaped ports; Laravel/Eloquent implementations are wired as adapters by the package service provider.
Read-side rule: workspace queries use `WorkspaceReadModel` plus explicit query DTOs; optimized Eloquent handlers live under Infrastructure and the legacy query service is only a compatibility delegator.
```

CI covers:

- Laravel 12 / PHP 8.2–8.5
- Laravel 13 / PHP 8.3–8.5
- PostgreSQL 17 and MySQL 8.4 real-database suites on both Laravel 12 and Laravel 13
- cross-process concurrency tests for quota, share limits, direct finalization and cloud-drive default election
- MinIO S3-compatible provider contract tests, including completed multipart uploads
- PostgreSQL/MySQL large-workspace query budgets and N+1/lazy-loading checks

The MinIO job builds the current community server and verifies Laravel/Flysystem and raw S3 direct-upload operations address the same physical objects, including configured disk roots and complete multipart upload/inspection.

`production:gate` is the release-facing local gate. Architecture debt is ratcheted: Domain/Application framework debt is now zero, while remaining oversized classes may only decrease during the Hexagonal/DDD refactor. See [`docs/PRODUCTION_BASELINE.md`](docs/PRODUCTION_BASELINE.md), [`docs/PUBLIC_API_BASELINE.md`](docs/PUBLIC_API_BASELINE.md), [`docs/PERFORMANCE_AND_INDEXES.md`](docs/PERFORMANCE_AND_INDEXES.md), and [`docs/RELEASE_CHECKLIST.md`](docs/RELEASE_CHECKLIST.md).

## Operational commands

```text
storage:health
storage:reconcile-usage
storage:cleanup-orphans
storage:cleanup-direct-uploads
storage:process-media
storage:retention
```

## First-release status

This repository is the clean first-release baseline. It intentionally contains no pre-release namespace aliases, legacy manager facade, `thumbnail_path` compatibility field, historical upgrade migrations, or duplicate legacy query APIs.

See [`docs/SCALE_V1.md`](docs/SCALE_V1.md) for the completed hardening scope and [`docs/HANDOFF.md`](docs/HANDOFF.md) for continuation notes.
### HTTP production hardening

When package routes are enabled, they use fail-closed authenticated middleware, named public/authenticated rate limiters, stable JSON error codes, and security headers. See `docs/STEP_10_LARAVEL_HTTP_HARDENING.md`. `StorageServiceProvider` also validates security-sensitive configuration during registration so invalid deployments fail before serving storage traffic.


### Production reliability matrix

`composer architecture:reliability` protects the package's real-database race tests, queue recovery, compensation/orphan contracts, provider-outage isolation, Eloquent relationship resolution and MinIO multipart completion coverage. The real PostgreSQL/MySQL suite runs on both supported Laravel majors. See `docs/STEP_11_PRODUCTION_RELIABILITY.md`.

### Final release hardening

`composer architecture:release` verifies stable package metadata, fail-closed defaults, the 46-endpoint HTTP compatibility surface, flattened first-release migrations, release-critical indexes, CI coverage, safe deployment examples and distribution hygiene. See `docs/STEP_12_RELEASE_HARDENING.md`.

## CI/CD and releases

Every pull request and `main` push runs the supported PHP/Laravel matrix, architecture/static-analysis/format/test gates, PostgreSQL/MySQL integration tests, query budgets and MinIO S3 contracts. Stable publication is performed through the gated GitHub **Release** workflow; it creates the tested Git tag and GitHub Release, after which the existing Packagist GitHub integration indexes the version. See [`docs/CI_CD.md`](docs/CI_CD.md).

