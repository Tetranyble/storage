# Capability Module Boundaries

Step 2 replaces the former package-wide `Application/Domain/Infrastructure` buckets with vertical capability ownership.

## Why this split exists

A technical-layer-only package made unrelated concepts neighbors and encouraged large services to coordinate everything. Capability ownership makes the unit of change explicit: upload rules belong to Upload, share rules belong to Sharing, cloud-provider behavior belongs to CloudDrive, and so on.

Each module may contain `Domain`, `Application` and `Infrastructure`. A module does **not** need all three layers when its current responsibility is smaller. Empty ceremonial layers are intentionally avoided.

## Boundary policy

1. `Domain` is the framework-neutral center. Step 3 removed all Laravel/Eloquent/Symfony HTTP leaks and the purity gate allows no exceptions.
2. `Application` coordinates use cases and should ultimately depend on Domain plus ports, never concrete adapters.
3. `Infrastructure` implements ports using Eloquent, Flysystem, provider SDKs, queues or network clients.
4. Laravel HTTP/console/facade/provider code stays outside capability cores.
5. `Shared` is a minimal kernel, not a dumping ground. It cannot import another feature module.
6. New cross-module dependency edges are forbidden by default. If collaboration is necessary, prefer an explicit port or published application contract.
7. The existing cross-module graph is transitional. Removing an edge is always allowed; adding one requires architecture review.

## Refactoring sequence from here

Step 3 is complete: Laravel/Eloquent types were removed from Domain and validated value objects were introduced and adopted in direct-upload/trust/storage domain messages. Step 4 moves lifecycle invariants into aggregates/state machines. Step 5 replaces Application → Infrastructure references with ports/use cases. Step 6 completed framework isolation of Application and moved Laravel/Eloquent translation to adapters. Those steps progressively collapse the cross-module cycle recorded in the Step 2 baseline.

## Step 5 status

Application-to-Infrastructure references are now zero. SQL/Eloquent-heavy query and persistence orchestration has been reclassified to adapter-side namespaces, while focused command classes depend on capability-shaped Application ports. The cross-module graph has also fallen below the Step 2 ceiling as responsibilities moved to their owning adapters; no new edge was introduced.

## Step 6 status

Application now has zero Laravel, Symfony HTTP, package HTTP, Support-helper or Infrastructure references. The cross-module graph remains below its Step-2 ceiling at 91/92 transitional edges, with no new Shared-kernel feature dependency.
