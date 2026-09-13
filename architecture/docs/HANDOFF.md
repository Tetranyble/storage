# Handoff

Canonical baseline: **Step 12 — Release Hardening complete (release candidate; stable tag requires green dependency-backed CI)**.

## Current architecture

`src/Modules/<Capability>/{Domain,Application,Infrastructure}` with Laravel delivery/composition adapters at the package edge. Domain and Application are framework-independent. Command use cases depend on explicit ports; Laravel HTTP/config/events/uploads and Eloquent persistence are translated by edge/Infrastructure adapters.

## Current production features

- workspace isolation + ACL
- quotas + reconciliation
- server, resumable and S3-compatible direct uploads
- sharing/download limits
- versioning
- cloud drives
- quarantine/trust pipeline
- queued processing
- first-class derivatives
- observability/health
- cursor queries and query benchmarks
- retention
- bulk operations
- orphan/failed-cleanup recovery

## Important design decisions

- PostgreSQL/MySQL concurrency semantics are tested in CI.
- `MediaDerivative` is canonical; there is no `thumbnail_path`.
- Direct uploads reserve quota before provider transfer.
- Object-store/DB mutations use compensation/durable cleanup rather than pretending they are one transaction.
- Package-owned storage models are not replaceable; only host User/Workspace integration is configurable.
- Package HTTP routes are opt-in.
- Provider SDKs are optional Composer dependencies.

## Local verification limitation in the ChatGPT build environment

The source artifact does not include `vendor/`, and Composer/PHPUnit/Larastan/Pint are unavailable in this execution environment. Static architecture and PHP syntax gates can be run here; dependency-backed runtime gates must be executed by CI or after `composer install` locally.

## Current refactor checkpoint

- Step 1: production gates and ratchets — complete.
- Step 2: capability module boundaries — complete.
- Step 3: Domain purification/value-object foundation — complete.
- Step 4: lifecycle aggregates and explicit state machines — complete.
- Step 5: ports and focused application use cases — complete.
- Step 6: framework isolation and Infrastructure adapters — complete.
- Step 7: provider Strategy + Registry and OAuth provider dispatch — complete.
- Step 8: explicit CQRS workspace read model, query DTOs and Eloquent handlers — complete.
- Step 9: trust processing pipeline, durable media-processing intent/dispatch leases, stale-worker recovery and bounded orphan cleanup — complete.
- Step 10: Laravel/API integration hardening, fail-closed routes, rate limits, stable errors and composition-root cleanup — complete.
- Step 11: production reliability matrix, real-DB race tests, provider outage contracts, Eloquent relationship smoke tests and complete MinIO multipart contract — complete.
- Step 12: canonical first-release schema, hot-path indexes, PostgreSQL/MySQL query budgets, N+1 protection, release metadata/hygiene, queue timing validation and final release gate — complete.
- Framework/concrete dependency debt: **0** in Domain and Application.
- Verified core metrics: **76 Domain files**, **70 Application files**, **14 required ports**, **11 required adapters/boundaries**, **8 CQRS query DTOs/handlers**, **91/92 transitional module edges**.
- Cloud providers: **8 registry-owned strategies**, with Google/OneDrive/Dropbox OAuth dispatch owned by their strategies. `ConnectedDriveService` is down from 878 to 638 lines and `OAuthService` from 321 to 77 lines. Default-drive concurrency is isolated in `ConnectedDriveDefaultCoordinator`.
- Processing recovery uses the existing `media` row as the narrow durable work intent instead of introducing a generic outbox table; extension events remain best-effort.
- Test inventory: **79 PHPUnit test classes / 484 `test_*` methods**. Real PostgreSQL/MySQL concurrency runs on Laravel 12 and 13; MinIO completes a real multipart direct upload; the dedicated PostgreSQL/MySQL query-performance lane enforces query budgets and lazy-loading safety.
- Release state: source-level release gates are green. Do not create the stable tag until the exact commit also passes Composer validation/audit, PHPUnit, PHPStan/Larastan, Pint, both real-database matrices, MinIO and query-performance CI.

## Recommended local verification

On a normal development machine:

```bash
composer install
composer validate --strict
composer run production:gate
composer benchmark:queries   # PostgreSQL and MySQL
composer audit
```

Then merge/develop normal features from this baseline.

## CI/CD

Release automation, GitHub repository settings and Packagist synchronization are documented in `docs/CI_CD.md`.
