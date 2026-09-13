# Production Readiness Baseline

This document freezes the Step 1 baseline before the Hexagonal/DDD refactor. It is intentionally descriptive: existing behavior is protected first, then architecture debt is removed behind tests.

## Supported Runtime

- PHP: `^8.2`
- Laravel components: `^12.0|^13.0`
- CI compatibility matrix: PHP 8.2–8.5 across the supported Laravel/Testbench combinations
- Database integration: PostgreSQL 17 and MySQL 8.4
- S3-compatible contract integration: current MinIO Community server

## Baseline Verification

The primary production gate is:

```bash
composer run production:gate
```

It runs, in order:

1. Layer and package architecture verification.
2. Dependency-debt ratchet.
3. Source-size/complexity ratchet.
4. PHPStan/Larastan static analysis.
5. Pint formatting check.
6. Full PHPUnit suite.

CI additionally executes `composer audit` and the real-database/provider jobs.

## Existing Test/Surface Baseline

At the start of Step 1 the package contains:

- 220 PHP source files.
- 72 Domain PHP files.
- 41 Application PHP files.
- 67 PHPUnit files containing approximately 440 `test_*` methods.
- 46 HTTP route declarations in `routes/storage.php`.
- PostgreSQL/MySQL concurrency and integration coverage.
- S3/MinIO provider contract coverage.
- Large-workspace query benchmark support.

These counts are not quality targets. They are reference points for detecting accidental surface loss during the refactor.

## Architectural Debt Frozen by Ratchet

Step 1 records 168 dependency occurrences across 92 tracked rule/file pairs. The most important target states are:

- Domain → Laravel: **0**.
- Domain → Symfony HTTP: **0**.
- Domain → Application/Infrastructure/HTTP: **0**.
- Application → concrete Infrastructure: **0**.
- Application command/core code → Laravel framework: **0**.
- HTTP semantics outside adapters: **0**.

The package also starts with 11 PHP source files above the 350-line budget. Existing oversized files may only shrink, and newly introduced classes must remain within the budget.

## Refactor Safety Rule

The baseline JSON files are not allowlists to normalize. They are migration ledgers. Refactoring work should reduce them. CI rejects new dependency leaks and growth of oversized classes before later production-readiness work begins.

## Public Compatibility Rule During Refactor

Until a deliberate breaking-release decision is made, refactoring must preserve the externally observable package surface captured in `PUBLIC_API_BASELINE.md`: route names/semantics, facades, package configuration contract, published migrations, and documented extension contracts. Internal classes are free to change when tests and adapters preserve behavior.


## Step 2 structural checkpoint

The core is now physically capability-owned under `src/Modules`. Module ownership and the transitional cross-module dependency graph are enforced by `scripts/check-module-boundaries.php`; new module edges are forbidden by default.


## Step 3 domain-purity checkpoint

Step 3 establishes a zero-tolerance framework-neutral Domain boundary. Laravel/Eloquent/HTTP-facing contracts were reclassified as Application orchestration contracts; Domain now has zero dependency-ratchet violations. Validated value objects were introduced for identifiers, storage paths/sizes, MIME/checksum values and direct-upload parts/ETags, and are used in provider inspection, trust scanning and storage-usage domain messages.

The remaining dependency baseline is 167 Application-layer occurrences across 91 rule/file pairs (down from 168 total occurrences in Step 2). These are transitional debt for Steps 5–6, not approved dependencies.

## Step 5 ports/use-case checkpoint

Application-to-Infrastructure dependency debt is now **zero**. Persistence/query/queue-heavy implementations were moved to Infrastructure and focused command use cases depend on explicit application ports backed by Eloquent adapters. The dependency ratchet fell from 167 occurrences after Step 4 to **59 occurrences across 40 files**, all in the remaining `application_laravel` rule. No Domain debt remains.

`scripts/check-application-boundaries.php` is a zero-tolerance gate: any new `Infrastructure` reference from Application fails immediately. The nine ports introduced in this step are also checked to remain free of Laravel, Symfony and Infrastructure dependencies.

## Step 6 adapter-isolation checkpoint

Domain and Application now have **zero** grandfathered framework/concrete-adapter dependency violations. `IncomingFile`, `UploadLimits`, `StorageEventPublisher` and the Eloquent/Laravel adapter set keep HTTP uploads, config, events, Eloquent access and mail attachments at the edge. The dependency baseline has been reset to an empty violation set so any future Domain/Application framework leak fails immediately.

## Step 8 CQRS checkpoint

Workspace reads now cross the `WorkspaceReadModel` Application port using eight immutable query DTOs and eight dedicated Eloquent handlers. `MediaLibraryController` no longer depends on the concrete workspace query adapter. `WorkspaceFileQueryService` shrank from 739 lines to 74 and is retained only for compatibility. The architecture now has 70 framework-independent Application PHP files, 14 required Application ports, zero tracked framework/concrete dependency debt, and 91/92 transitional module edges. The complexity baseline was tightened to current line counts, preventing previously-shrunk oversized classes from regrowing to historical limits.


## Step 9 async-resilience checkpoint

The trust/processing path is now an explicit content-inspection → malware-scan → post-processing pipeline. The authoritative `media` row doubles as the narrow durable processing intent: dispatch leases/backoff prevent duplicate enqueue storms while `storage:process-media` recovers pending rows, lost queue handoffs and stale worker leases. Hard queue retries can reclaim a timed-out worker lease without making ordinary duplicate first-attempt jobs non-idempotent.

SQL/object-storage compensation remains Saga-style and `storage_orphans` cleanup is now bounded by retry scheduling plus an explicit abandoned state. Generic Laravel extension events intentionally remain best-effort rather than introducing a package-wide outbox. `architecture:resilience` protects these decisions. Current Domain count is 76 framework-neutral files; Application remains 70 framework-neutral files with zero tracked dependency debt and 91/92 transitional module edges.


## Step 12 release-hardening checkpoint

The refactor has reached its release-candidate gate. The first stable schema is flattened into 12 canonical migrations and release-critical indexes are asserted on fresh install. PostgreSQL/MySQL CI includes a dedicated query-performance lane with N+1/lazy-loading protection. The release tree has an executable `architecture:release` gate covering metadata, fail-closed defaults, the 46-route public surface, schema/index invariants, CI coverage and artifact hygiene.

Current dependency debt remains **0** in Domain/Application, with **91/92** transitional module edges and **9** grandfathered oversized source files that may only shrink. The local ChatGPT build environment still cannot execute Composer-backed PHPUnit/PHPStan/Pint; therefore a stable tag is permitted only after the exact release commit passes the complete dependency-backed CI gate.
