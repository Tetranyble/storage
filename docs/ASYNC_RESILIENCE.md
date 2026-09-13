# Step 9 — Processing, Security and Async Resilience

Step 9 hardens failure semantics without introducing a generic message platform.

## Durable media-processing intent

The authoritative `media` row is the durable processing intent. New/content-changing media remains recoverable as `PENDING`; queue handoff records `processing_dispatch_attempts`, `processing_dispatched_at` and `processing_available_at`.

`storage:process-media` recovers:

- due `PENDING` work after enqueue failures;
- `QUEUED` rows whose dispatch lease expired; and
- `PROCESSING` rows whose worker lease became stale.

Recent duplicate queue handoffs are ignored. A genuine queue retry (`attempts() > 1`) may reclaim a row left `PROCESSING` by a hard worker timeout. The queue payload remains an integer media ID and dispatch remains `afterCommit`.

This is intentionally narrower than a generic transactional outbox. Ordinary package Laravel events are integration notifications and remain best-effort; package correctness does not depend on their delivery.

## Trust pipeline

Processing order is explicit and executable:

1. content/signature inspection;
2. malware scanning;
3. post-processing/derivative generation.

A MIME/content mismatch, detected malware, scanner failure, or scanner skip while scanning is mandatory stops the pipeline before derivative generation. Blocked malware and unsafe-content states retain distinct quarantine reasons. Scanner/runtime failures remain retryable queue failures.

## SQL ↔ object storage compensation

SQL and object storage are not treated as one atomic resource. Existing upload/move/delete flows keep their Saga-style compensation behavior: clean up copied/new objects when SQL fails; after SQL commits, failed physical retirement becomes a durable `storage_orphans` intent.

Orphan cleanup now has bounded retry/backoff using `next_attempt_at`. When `STORAGE_ORPHAN_MAX_ATTEMPTS` is reached, the record is marked `abandoned_at` instead of becoming a poison record retried forever. `storage:cleanup-orphans --retry-abandoned` is an explicit operator override.

Health reports abandoned cleanup and expired processing/dispatch leases so silent recovery failures become operationally visible.

## Schema additions

Upgrade migrations add:

- `media.processing_dispatch_attempts`
- `media.processing_dispatched_at`
- `media.processing_available_at`
- `storage_orphans.next_attempt_at`
- `storage_orphans.abandoned_at`

Both tables receive recovery-oriented indexes.

## Verification

`composer run architecture` includes `architecture:resilience`. The gate executes the pure retry policies and statically protects queue-after-commit semantics, stale-worker recovery, trust-pipeline ordering and bounded orphan cleanup.
