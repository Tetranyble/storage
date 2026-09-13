# Performance and Index Contract

The package treats query shape and schema indexes as part of production correctness for multi-tenant workspaces.

## Required release checks

`composer benchmark:queries` seeds a representative large workspace and executes query-count, query-plan and lazy-loading checks. CI runs the benchmark against PostgreSQL and MySQL rather than relying on SQLite query-planner behavior.

The benchmark is intentionally based on deterministic query budgets rather than hard wall-clock limits because shared CI runners are noisy. Elapsed time and memory usage are still emitted for trend monitoring.

## Indexed hot paths

Fresh migrations include dedicated indexes for:

- workspace/folder visibility and cursor ordering;
- media updated/created/trash cursor reads;
- temporary-media retention;
- media-processing recovery leases;
- collaborator and star lookup plus latest-grant/latest-star ordering;
- resumable/direct-upload expiry, health and retention scans;
- connected-drive status/token-expiry health checks;
- due/abandoned storage-orphan recovery;
- activity visibility and activity cursors.

`PackageMigrationsInstallTest` asserts the release-critical index names so refactors cannot silently drop them.

## N+1 policy

Read-model handlers must project from explicitly loaded state. The benchmark suite enables Eloquent lazy-loading prevention around primary workspace browse/search reads. Breadcrumb ancestry is fetched in one bounded query from materialized folder paths rather than walking the `parent` relation one query at a time.

## Search note

Portable substring search currently uses SQL `LIKE` predicates so the package behaves consistently across supported databases. Large deployments that need full-text ranking should provide a search-specific adapter/service rather than weakening workspace ACL predicates or replacing the canonical persistence indexes.
