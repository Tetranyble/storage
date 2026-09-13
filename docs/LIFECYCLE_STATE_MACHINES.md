# Lifecycle state machines

Step 4 moves lifecycle invariants out of Eloquent services and into framework-free Domain objects. SQL locking, timestamps, provider calls, object cleanup and atomic counters remain Infrastructure responsibilities.

## Direct uploads

`DirectUploadLifecycle` owns legal transitions and quota-reservation release semantics:

```text
PENDING -> UPLOADING -> FINALIZING -> FINALIZED
   |           |             |
   +-> EXPIRED +-> CANCELLED  +-> UPLOADING (retryable failure)
   |           |             |
   +---------- +-----------> FAILED
```

Cancellation, expiry and terminal failure return the reservation bytes that Infrastructure must release atomically. Successful finalization clears the session reservation without reducing workspace usage because the bytes have become committed media usage.

## Resumable uploads

`ResumableUploadLifecycle` owns whether a session may receive chunks, enter assembly, finish, cancel, expire or become conflicted:

```text
PENDING <-> UPLOADING -> ASSEMBLING -> FINALIZED
   |           |             |
   +-----------+-> EXPIRED   +-> PENDING/UPLOADING on retryable rollback
   +-----------+-> CANCELLED
   +-----------+-> CONFLICTED
```

Chunk completeness, byte totals and row locking remain persistence/application concerns; lifecycle legality does not.

## Shares

`ShareAccessPolicy` owns expiry, download-limit and view-vs-download access rules. Password hashing/checking and the atomic `downloads_count` SQL update remain adapters because they depend on Laravel hashing and concurrency-safe persistence.

## Version groups

`VersionGroup` owns revision deletion invariants and next-version calculation. `media_version_groups` remains the database serialization point so concurrent revisions cannot allocate the same version.

## Executable gate

`php scripts/check-lifecycle-domain.php` runs without Composer or Laravel and exercises the core transitions. `composer run architecture` includes this gate before module/dependency/complexity checks.

The PHPUnit suite also contains `tests/Unit/Domain/LifecycleStateMachinesTest.php` for exhaustive domain-level assertions when development dependencies are installed.
