# Changelog

All notable changes to `tetranyble/storage` are documented here. The package follows Semantic Versioning once the first stable tag is published.

## [Unreleased]

### Added

- Modular Hexagonal architecture with capability-owned Domain, Application and Infrastructure boundaries.
- Framework-neutral Domain and Application cores with executable dependency gates.
- Explicit upload lifecycle state machines and validated value objects.
- Application ports, infrastructure adapters and provider strategy registry.
- CQRS read model for workspace/library queries.
- Recoverable asynchronous media processing, trust/quarantine pipeline and bounded object-storage compensation.
- Hardened Laravel HTTP boundary, stable error contracts, rate limiting and fail-closed configuration validation.
- MySQL/PostgreSQL concurrency tests, MinIO multipart provider contracts and real-database query-performance CI.
- Operator health/recovery commands, sanitized telemetry and release-readiness verification.
- Gated GitHub Actions CI/CD with reusable release CI, deterministic release archives, GitHub Releases, Packagist synchronization verification and Dependabot maintenance.
- CircleCI verification fallback mirroring the PHP/Laravel, MySQL/PostgreSQL, query-performance and MinIO release-critical test lanes without release/publication authority.

### Changed

- Fresh-install migrations are flattened to the canonical first-release schema; pre-release additive migrations are not shipped.
- Query indexes now cover workspace cursor reads, trash/retention scans, upload recovery, cloud-drive expiry checks, and latest grant/star ordering.
- Workspace breadcrumbs are projected with a bounded ancestor query rather than relation-by-relation lazy loading.

### Fixed

- Restored `WorkspaceContext` implementation variance so the Laravel adapter honors the object-based HTTP contract on PHP 8.2-8.5.
- Corrected MIME type validation delimiter handling used by direct-upload/S3 inspection.
- Made large-workspace benchmarks seed required UUIDs explicitly on PostgreSQL and MySQL.
- Corrected post-modularization storage-service imports and Dropbox download stream handling.
- Added explicit Eloquent attribute contracts and generic-model attribute access required by Larastan.
- Corrected streamed-response typing, soft-delete query typing, activity relation metadata and derivative query typing.

### Security

- Package HTTP routes remain disabled by default.
- Remote fetching blocks private networks by default.
- Malware scanning can enforce private quarantine storage and fails closed by default when enabled.
- Public and authenticated package traffic use separate rate-limit policies.
