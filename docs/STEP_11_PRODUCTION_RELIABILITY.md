# Step 11 — Production Reliability & Test Matrix

Step 11 turns the package's architectural guarantees into executable failure-mode contracts. It does not introduce another runtime architecture layer: production safety is proven at the seams where concurrency, remote systems, queues and object storage can fail.

## Reliability matrix

| Risk | Executable proof |
|---|---|
| Quota race | Two real DB processes attempt reservations that cannot both fit; exactly one succeeds. |
| Resumable session uniqueness | PostgreSQL/MySQL enforce one active browser upload identifier across processes. |
| Share download limit race | Two real DB processes contend for the final download slot; one succeeds and one is rejected. |
| Direct-upload double finalize | Concurrent finalizers can produce only one media row and cannot double-charge workspace quota. |
| Cloud-drive first-default race | Two first connections both succeed while exactly one drive becomes default. |
| Queue worker death | Stale `PROCESSING` leases are recovered; recent leases are not duplicated. |
| Queue handoff failure | Dispatch failures remain durable/retryable with backoff metadata. |
| Object-store / DB split-brain | Upload, delete, rename, attach and cloud-import compensation tests verify rollback/orphan behavior. |
| Orphan poison objects | Cleanup retries are bounded and abandoned objects become operator-visible. |
| Provider outage | Remote browse failure escapes without mutating workspace quota or drive state. |
| S3 direct upload | MinIO proves single PUT and complete multipart upload/inspection against the same rooted object namespace. |
| Tenant isolation | Controller/use-case tests prove foreign-workspace resources are not resolved through submitted identifiers. |
| Modular Eloquent relationships | Relationship smoke tests resolve related model classes after capability namespace moves. |
| Framework/database support | CI covers Laravel 12/13, PHP 8.2–8.5 where supported, PostgreSQL 17, MySQL 8.4, and MinIO. |

## Real database concurrency

`tests/Feature/DatabaseConcurrencyTest.php` is intentionally skipped on SQLite. The CI database jobs install `pcntl` and execute the full test suite against PostgreSQL and MySQL so `SELECT ... FOR UPDATE`, unique constraints and transaction behavior are exercised by separate OS processes and separate DB connections.

Database CI is run for both supported Laravel majors:

- PostgreSQL 17 / Laravel 12
- MySQL 8.4 / Laravel 12
- PostgreSQL 17 / Laravel 13
- MySQL 8.4 / Laravel 13

The first-drive election was hardened during this step. `ConnectedDriveDefaultCoordinator` locks the workspace row before deciding whether a newly connected drive owns the single default slot. This prevents two concurrent first connections from both observing an empty drive set and racing on the unique default-slot index.

## Provider contract depth

The MinIO contract no longer stops at multipart creation/abort. It uploads two real parts, completes the multipart upload through `DirectUploadGateway`, inspects the completed provider object and verifies its final size. This catches root-prefix, upload-id, ETag and completion-contract mismatches that mocks cannot detect.

## Relationship regression found by the matrix

The modular refactor left several Eloquent relationship methods referring to old sibling namespaces. PHP lint accepts `Folder::class` even when it resolves to a nonexistent class, so syntax/architecture checks did not expose the problem. Step 11 fixed those relationships and added `EloquentRelationshipResolutionTest` plus a dependency-free static relationship check inside `architecture:reliability`.

## Reliability architecture gate

Run:

```bash
composer architecture:reliability
```

The gate verifies that the repository keeps executable contracts for concurrency, processing recovery, compensation, provider outage isolation, S3 multipart completion, tenant isolation and Eloquent relationship resolution. It also verifies that CI still contains the supported PHP/Laravel/database/provider matrix.

This gate is part of `composer run architecture` and therefore part of `composer run production:gate`.

## Test inventory at this checkpoint

The repository contains 78 PHPUnit test files and 480 `test_*` methods:

- 366 unit tests
- 109 feature tests
- 4 integration tests
- 1 benchmark test outside the `test_*` count convention used above

These counts are a source inventory, not a claim that PHPUnit executed inside the artifact-building environment. Composer and `vendor/` are unavailable there; dependency-backed execution remains CI/local-machine responsibility after `composer install`.
