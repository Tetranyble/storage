# Step 12 — Release Hardening

Step 12 turns the architecture/reliability work into a release candidate that can only be tagged after the complete dependency-backed CI gate succeeds on the exact commit.

## Release schema

The package has not shipped a stable release yet, so the first stable schema is intentionally flattened. Processing recovery fields and storage-orphan retry scheduling now live in their canonical `create_*` migrations rather than upgrade-style migrations.

Release-critical indexes cover:

- media/folder deleted cursors,
- temporary-media retention scans,
- processing recovery scans,
- collaborator/star latest-access ordering,
- resumable/direct-upload health/recovery scans,
- connected-drive token-expiry scans, and
- due orphan-compensation cleanup.

`PackageMigrationsInstallTest` asserts the canonical schema and index names so the release schema cannot silently drift.

## Query performance and N+1 protection

The workspace read side has explicit PostgreSQL/MySQL performance CI. The benchmark fixture exercises a large workspace and enforces query-count budgets for the CQRS handlers.

Laravel lazy loading is disabled in the N+1 benchmark. Browse breadcrumbs are resolved with one bounded path-prefix query instead of recursively lazy-loading parent folders.

Run locally against a real database with:

```bash
STORAGE_RUN_QUERY_BENCHMARKS=1 composer benchmark:queries
```

The stable release must pass this command against both PostgreSQL and MySQL.

## Queue timing

The default processing timeout is 60 seconds. For Laravel queue connections that expose `retry_after`, the package configuration validator requires:

```text
queue retry_after > storage processing timeout
```

This prevents a second worker from receiving the same job while the first worker is still allowed to execute it. Provider-managed visibility timeouts such as SQS must be configured by the host to preserve the same invariant.

## Release metadata and hygiene

The release tree includes `CHANGELOG.md`, `SECURITY.md`, `CONTRIBUTING.md`, the public API baseline, operator documentation, performance/index documentation and the release checklist.

`architecture:release` rejects:

- missing release artifacts,
- package/runtime support drift,
- unsafe default configuration,
- undocumented HTTP-surface changes,
- pre-1.0 additive migration history,
- missing release-critical indexes,
- missing CI compatibility/performance/audit coverage,
- unsafe `.env.example` defaults,
- nested archives, private keys, runtime databases/logs, and
- debug terminators/dumps in production PHP surfaces.

## Tagging rule

A generated source archive passing the dependency-free gates is a **release candidate**, not proof that the dependency-backed release is green. Tag the stable version only from a commit where CI has successfully executed:

```bash
composer validate --strict
composer audit
composer run production:gate
composer benchmark:queries
```

plus the supported PHP/Laravel matrix, PostgreSQL/MySQL integration suites, cross-process concurrency tests and MinIO multipart contract.

Do not bypass a failing release gate by rebasing a ratchet or increasing a complexity ceiling. Fix the regression or deliberately document a public breaking change according to SemVer.
