# Operations

## Health

```bash
php artisan storage:health
php artisan storage:health --json
php artisan storage:health --strict
```

Health is read-only. It checks database/storage connectivity, quota drift, orphan backlog, resumable/direct uploads, processing backlog, and connected-drive errors.

## Recovery

```bash
php artisan storage:cleanup-orphans
php artisan storage:cleanup-orphans --retry-abandoned
php artisan storage:cleanup-direct-uploads --limit=500
php artisan storage:process-media --limit=500
php artisan storage:process-media --limit=500 --retry-failed
php artisan storage:reconcile-usage
```

Run `storage:process-media` on a frequent scheduler (normally every minute). It recovers due `PENDING` intents, stale `QUEUED` dispatch leases and `PROCESSING` rows left behind by terminated workers. Queue dispatch failures back off through `processing_available_at`; first-attempt duplicate jobs remain idempotent, while a genuine queue retry can reclaim a row left by a hard timeout.

Queue visibility must outlive worker execution. The default `STORAGE_PROCESSING_TIMEOUT` is 60 seconds. For Laravel database/Redis-style connections with `retry_after`, configure `retry_after > STORAGE_PROCESSING_TIMEOUT`; production configuration validation rejects the unsafe inverse. For provider-managed visibility timeouts (for example SQS), preserve the same invariant in the provider configuration.

Run `storage:cleanup-orphans` periodically as well. Failed object deletions use bounded backoff; after `STORAGE_ORPHAN_MAX_ATTEMPTS` the row is marked abandoned and health becomes critical. `--retry-abandoned` is an explicit operator override after the underlying provider/path issue has been corrected.

The package intentionally does **not** put ordinary Laravel extension events into a generic transactional outbox. Those events are best-effort integration notifications. Authoritative processing delivery is recovered from the `media` row itself, and SQL/object-store mutations use durable compensation records.

## Retention

Dry run:

```bash
php artisan storage:retention
```

Apply only after enabling `STORAGE_RETENTION_ENABLED=true`:

```bash
php artisan storage:retention --apply
```

## Monitoring

`StorageTelemetry` emits sanitized structured event/counter/gauge/timing records. Integrate `StorageTelemetryRecorded` with the host monitoring stack. Package telemetry is best-effort and cannot fail storage operations.

## Provider validation

CI includes PostgreSQL/MySQL integration jobs and a MinIO S3 provider-contract job. Run provider-specific integration tests before changing raw SDK/Flysystem path behavior.
