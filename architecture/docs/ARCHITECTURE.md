# Architecture

The package uses a **modular Hexagonal architecture with selective tactical DDD**. Technical layers are local to a capability instead of being package-wide buckets.

## Source topology

```text
src/
├── Modules/
│   └── <Capability>/
│       ├── Domain/
│       ├── Application/
│       └── Infrastructure/
├── Http/          # Laravel HTTP adapter
├── Console/       # Laravel CLI adapter
├── Facades/       # Laravel package facade surface
├── Events/        # Laravel-facing integration events
├── Concerns/      # Host-model integration traits
├── Support/       # Laravel package configuration support
└── StorageServiceProvider.php
```

`architecture/modules.json` is the authoritative capability catalog. A source module that is not in that catalog fails the architecture gate.

## Capability boundaries

The package currently owns these capabilities:

- **Media** — authoritative media lifecycle, metadata, comments and media persistence.
- **Storage** — filesystem abstraction, placement, object lifecycle and orphan cleanup.
- **Upload** — server-mediated resumable uploads.
- **DirectUpload** — provider-direct upload sessions, signing and finalization.
- **Processing** — media processing, derivatives and asynchronous processing.
- **Trust** — inspection, virus scanning, quarantine and delivery trust policy.
- **Sharing** — share lifecycle and download constraints.
- **Access** — authorization, collaborator grants and SQL visibility.
- **Versioning** — media revisions and version-group behavior.
- **CloudDrive** — external drive connectivity and provider adapters.
- **Workspace** — workspace identity/resolution and workspace-scoped reads.
- **Activity** — activity recording and feeds.
- **Remote** — safe remote-media import.
- **Transfer** — transfer authorization/orchestration.
- **Folder**, **Download**, **Health**, **Quota**, **Observability** — focused supporting capabilities.
- **Shared** — deliberately tiny shared kernel. It may not depend on feature modules.

## Dependency direction

Inside a capability the target direction is:

```text
Driving adapters (HTTP / Console / Queue)
                  |
                  v
             Application
                  |
                  v
               Domain
                  ^
                  |
         Infrastructure adapters
```

Domain cannot depend on Application or Infrastructure. HTTP semantics stay outside module cores. As of Step 3, Domain has zero Laravel/Eloquent/Symfony HTTP leaks and is protected by a zero-tolerance purity gate. As of Step 6, Application also has zero Laravel/HTTP/Support/Infrastructure dependencies; framework translation is performed by adapters at the package edge.

Cross-capability dependencies are also ratcheted. `architecture/module-dependency-baseline.json` records the transitional graph discovered during Step 2. New edges fail CI. Existing edges may be removed without changing the baseline upward.

The current graph exposes a historical strongly-connected cluster across Access, Activity, DirectUpload, Folder, Media, Processing, Remote, Sharing, Storage, Trust, Upload and Versioning. That cycle is **not the target architecture**. Steps 3–6 break it progressively. Step 3 established framework-neutral Domain vocabulary; Steps 4–6 moved lifecycle behavior and inter-module collaboration behind explicit ports/use cases and isolated Laravel/Eloquent translation in adapters.

## Laravel is an adapter

`Http`, `Console`, package facades, events and `StorageServiceProvider` remain outside `Modules`. They are integration surfaces and composition-root concerns, not business capabilities. Module cores must never import `Http`.

## Authoritative invariants

- ACL visibility is evaluated before pagination.
- Upload maximums are enforced below controllers.
- Workspace quota mutation is atomic.
- Direct-upload reservations are part of authoritative usage.
- Version numbers are database-unique per version group.
- Share download slots are consumed atomically.
- Permanent deletion is database-first with durable post-commit object cleanup.
- Storage relocation is copy → DB commit → source retirement.
- Media processing is stateful, retryable, quarantine-aware and recoverable after lost queue/worker leases.
- Derivatives are first-class storage objects with independent quota accounting.

## Persistence and object storage

Package-owned Eloquent models are Infrastructure adapters owned by their capability, for example `Modules/Media/Infrastructure/Persistence/Eloquent/Models/Media`. Host user/workspace integration remains configurable.

Object storage is not transactionally coupled to SQL. Mutations therefore use a Saga-style compensation rule and durable `storage_orphans` cleanup intents. Cleanup retries use bounded backoff and an explicit abandoned state; no operation should leave a live row pointing at an object removed because a later SQL write failed.

Media processing deliberately uses the authoritative `media` row as a narrow transactional work intent rather than adding a second generic outbox table. Creation/content-changing transactions leave processing state recoverable; queue handoff adds a dispatch lease, and `storage:process-media` recovers pending intents, lost queue handoffs and stale worker leases. Generic Laravel extension events remain synchronous/best-effort because package correctness does not depend on their delivery.

The trust pipeline order is explicit: content inspection → malware scan → post-processing/derivatives. Unsafe content, infection and scanner failure stop the pipeline before derived objects are generated.

## Queries

Complex read paths do not need to hydrate domain aggregates. Global search, recent resources and activity remain optimized read-model concerns with cursor pagination, stable tie-breaker ordering and ACL predicates applied before pagination. Step 8 makes this CQRS separation explicit.

## Executable rules

Run `composer run architecture`. It enforces:

1. syntax and namespace/path integrity;
2. zero-tolerance Domain purity;
3. provider Strategy/Registry isolation for cloud-drive adapters;
4. framework-free lifecycle state-machine behavior;
5. layer dependency direction;
6. capability ownership and the cross-module dependency ratchet;
7. framework-dependency debt ratchets;
8. the source complexity ratchet;
9. async resilience: queue leases, stale-worker recovery, trust-pipeline ordering and bounded orphan retries;
10. existing storage/schema/query/lifecycle invariants.

Never increase an architecture baseline merely to make CI green. Baselines only move downward as debt is removed, or change alongside an explicit architecture decision.

## Application ports and adapter isolation (Steps 5–6)

Application code may depend on Domain concepts and Application ports, but may not reference `Infrastructure`, Laravel, Symfony HTTP, package HTTP adapters or Laravel-aware Support helpers. `scripts/check-application-boundaries.php` enforces this at zero tolerance. Laravel/Eloquent adapters implementing those ports are composed in `StorageServiceProvider`.

Optimized SQL/Eloquent read models are adapter-side query implementations rather than domain aggregates. This keeps the command model focused on invariants while allowing read paths to remain database-efficient.


## CQRS workspace read model (Step 8)

Workspace HTTP reads depend on the framework-neutral `WorkspaceReadModel` port and immutable query DTOs. Eight Eloquent handlers own optimized browse, trash, starred, shared-with-me, version-history, search, recent and activity queries. `WorkspaceFileQueryService` is now only a thin compatibility adapter; it contains no SQL/Eloquent composition. `scripts/check-read-model-cqrs.php` prevents that separation from regressing. See [`CQRS_READ_MODEL.md`](CQRS_READ_MODEL.md).

## Cloud provider strategies (Step 7)

`ConnectedDriveService` is provider-neutral orchestration. `CloudProviderRegistry` resolves a registered `CloudProviderStrategy`; each strategy owns its optional package requirements, adapter construction and credential preparation. The registry is exposed by the Laravel composition root so provider implementations can be replaced without modifying orchestration. `scripts/check-provider-registry.php` prevents provider factories from drifting back into the central service.
