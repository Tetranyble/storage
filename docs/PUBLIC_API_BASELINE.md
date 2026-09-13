# Public API Baseline

This file identifies the package surfaces that must not disappear accidentally while internals are re-architected.

## Laravel Integration

The package provider is `Tetranyble\Storage\StorageServiceProvider`. Composer exposes these facades:

- `TetranybleMediaUpload`
- `TetranybleMediaVersioning`
- `TetranybleMediaMail`
- `TetranybleCloudDrive`
- `TetranybleStorageQuota`
- `TetranybleMediaSharing`
- `TetranybleMediaAccess`

## HTTP Surface

`routes/storage.php` is configurable through `tetranyble-storage.routes`. The baseline route families are:

- Public token share download.
- Media upload/import/show/update/delete/current selection.
- Media download and ZIP download.
- Storage copy/move operations.
- Connected-drive default/copy/move operations.
- Resumable upload create/show/chunk/finalize/cancel.
- Direct upload create/show/sign/finalize/cancel.
- Library index/search/recent/activity/usage/trash.
- Library upload, folder operations, media trash/restore/force-delete/move/rename.
- Bulk trash/restore/delete/move.
- Media share create/revoke.

Route enablement, prefixes, middleware, controller substitution and naming remain configuration-driven.

## Extension Contracts

Framework-neutral extension ports remain in capability Domain namespaces where they describe genuine domain/provider capabilities (for example cloud adapters, direct-upload gateways, observability and trust/scanning). Laravel-facing orchestration contracts that accept Eloquent models, requests/uploads, paginator collections or streamed responses were reclassified in Step 3 under the owning capability's `Application\Contracts` / `Application\DTO` namespaces.

This package is pre-production, so those internal namespace changes intentionally favor correct dependency direction over preserving accidental internals. The documented Laravel integration, facades and HTTP behavior remain the compatibility surface. Step 5 introduced framework-neutral command-side ports and removed all Application → Infrastructure references. Step 6 removed the remaining Laravel framework/transport types from Application and completed adapter isolation while preserving the documented facades and HTTP surface. Step 7 moved cloud adapter construction and credential validation behind a provider strategy registry; the existing provider-specific connection helpers remain compatible and `connectCredentials()` is an additive generic connection entry point. Step 8 moves workspace reads behind the additive `WorkspaceReadModel` port and explicit query objects while retaining every existing `WorkspaceFileQueryService` public read method as a compatibility delegator. Step 9 leaves routes/facades unchanged while adding internal processing-recovery/orphan-retry schema columns and additive command options; existing `storage:process-media` and `storage:cleanup-orphans` commands remain available. Step 10 hardens the Laravel HTTP edge without changing the 46-endpoint route surface. Step 11 adds reliability tests without changing the public contract. Step 12 keeps the package pre-1.0 schema canonical by folding those unreleased recovery columns into the original create migrations; no stable upgrade path is being rewritten because no stable release exists yet.

## Persistence and Schema

The fresh-install migrations are part of the release contract. Refactoring must preserve current integrity invariants, unique constraints and query-supporting indexes unless a migration change is intentional and tested on fresh install plus supported databases.

## Compatibility Policy for the Refactor

This package is still pre-production, so we will prefer a clean internal architecture over preserving accidental internal APIs. However, externally documented Laravel integration and HTTP behavior should only change deliberately, with tests and migration/release notes updated in the same change.
