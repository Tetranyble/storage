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

### Changed

- Fresh-install migrations are flattened to the canonical first-release schema; pre-release additive migrations are not shipped.
- Query indexes now cover workspace cursor reads, trash/retention scans, upload recovery, cloud-drive expiry checks, and latest grant/star ordering.
- Workspace breadcrumbs are projected with a bounded ancestor query rather than relation-by-relation lazy loading.

### Security

- Package HTTP routes remain disabled by default.
- Remote fetching blocks private networks by default.
- Malware scanning can enforce private quarantine storage and fails closed by default when enabled.
- Public and authenticated package traffic use separate rate-limit policies.
