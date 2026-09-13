# Step 5 — Ports and Application Use Cases

Step 5 completes the first dependency-inversion pass at the Application boundary.

## Enforced invariant

`src/Modules/*/Application/**` has **zero references to `Infrastructure`**. This is enforced without a grandfathered baseline by `scripts/check-application-boundaries.php` and also by the dependency ratchet.

The focused command classes remain the application use cases (`UploadMedia`, `MoveMedia`, `RenameMedia`, `DeleteMedia`, `CreateFolder`, `CreateMediaShare`, direct/resumable upload commands, etc.). They now collaborate through capability-shaped ports instead of Eloquent implementation classes.

## New command-side ports

- `WorkspaceResourceLocator` — resolves a resource inside a workspace boundary.
- `MediaLibrary` — command-side folder/root/trash lifecycle operations.
- `MediaDeletion` — permanent media deletion capability.
- `MediaRelocation` — rename/move capability.
- `MediaRevisionWriter` — revision creation/restoration capability.
- `MediaProcessing` — processing dispatch capability.
- `MediaShares` — share lifecycle capability.
- `CurrentMediaSelection` — current-version selection capability.
- `MediaVersioning` — version history/lifecycle capability.

These Step-5 ports are framework-neutral. Eloquent/Laravel translation lives in Infrastructure adapters registered by `StorageServiceProvider`.

## Reclassified implementation services

Persistence/query/queue-heavy classes that were incorrectly inside Application now live on the adapter side, including the workspace file query service, media library implementation, sharing implementation, processing dispatcher/processor, health/retention services, versioning persistence service, download implementation and bulk/comment persistence services.

This is intentional. Application owns orchestration and ports; Infrastructure owns Eloquent, queue jobs, SQL-optimized queries and provider implementation details.

## Step 6 completion

The 59 Laravel occurrences described at the end of Step 5 are now zero. Upload files, configuration limits, event dispatch, Eloquent access/direct/resumable operations, workspace HTTP resolution and mail attachment creation are translated by adapters. See `docs/ADAPTER_ISOLATION.md`.
