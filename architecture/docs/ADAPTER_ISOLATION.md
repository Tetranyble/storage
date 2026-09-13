# Step 6 — Infrastructure as Adapters

Step 6 completes framework isolation for the package core.

## Zero-tolerance boundaries

Both `src/Modules/*/Domain/**` and `src/Modules/*/Application/**` are now framework-independent. Application code is rejected if it references:

- `Illuminate\\`;
- Symfony HTTP transport classes;
- any module `Infrastructure` namespace;
- the package `Http` adapter namespace;
- Laravel-aware `Support` helpers; or
- Laravel/global helpers such as `config()`, `collect()`, `event()` or `app()`.

`architecture/dependency-baseline.json` now contains **zero grandfathered violations**. Framework/concrete-adapter debt can therefore no longer be reintroduced by ratchet rebasing.

## Translation at the edges

- `IncomingFile` is the application-owned representation of a local incoming upload. `LaravelIncomingFile` translates Laravel `UploadedFile` objects at the HTTP/host edge.
- `UploadLimits` owns the application requirement for size limits. `ConfiguredUploadLimits` reads Laravel configuration.
- `StorageEventPublisher` owns lifecycle notification intent. `LaravelStorageEventPublisher` dispatches the package's existing Laravel integration events.
- `MediaUploader`, `ResumableUploadManager`, `DirectUploadManager` and `ResourceAccessControl` are framework-neutral ports. Eloquent/Laravel adapters validate opaque host resources and delegate to existing persistence/provider services.
- `ResourceState` centralizes Eloquent attribute/key/mutation translation for application use cases. The Remote capability uses its narrower `ResourceIdentity` port so it does not create a new dependency on the Shared kernel.
- Laravel workspace/request resolution lives under `Http\\Contracts\\WorkspaceContext`; it is no longer an Application contract.
- Host-model `WorkspaceSubject` integration lives at the package integration edge under `Contracts`, not inside a capability Application layer.
- Laravel mail `Attachment` construction lives in `Http\\Mail\\LaravelMediaMailService`; the application mail service only produces transport-neutral `MediaMailPayload` values.

Host workspace/user/media handles intentionally remain opaque `object` values in several ports because host applications may supply their own Eloquent model classes. Concrete adapters are responsible for validating and translating those handles. Later domain/use-case work can replace more opaque handles with capability records where doing so improves invariants without constraining host integration.

## Composition root

`StorageServiceProvider` binds all core ports to adapters. The application core does not use the container directly.

## Verification

Run:

```bash
composer run architecture
```

The Application boundary gate scans every module Application PHP file and fails on any framework, HTTP, Support or concrete Infrastructure dependency.


## Verified checkpoint metrics

The dependency-free Step 6 gate verifies:

- 72 Domain files with zero framework/Application/Infrastructure leaks;
- 58 Application files with zero Laravel/HTTP/Support/Infrastructure references;
- 13 explicitly required framework-neutral application ports;
- 11 required adapter/boundary implementations;
- 0 grandfathered Domain/Application framework dependency violations; and
- 91 of the Step 2 ceiling of 92 transitional cross-module edges, with no new edge accepted.
