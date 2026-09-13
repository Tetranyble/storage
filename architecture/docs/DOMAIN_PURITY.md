# Domain Purity — Step 3

Step 3 makes `src/Modules/*/Domain` a strict framework-neutral boundary.

## Zero-tolerance rule

Domain code may not depend on:

- Laravel / `Illuminate`;
- Symfony HTTP response/request types;
- Application or Infrastructure layers;
- package HTTP adapters;
- package `Support` configuration helpers.

`scripts/check-domain-purity.php` enforces this with **no baseline allowance**. Unlike the broader dependency ratchet, a Domain violation cannot be grandfathered.

## Reclassified integration contracts

The previous Domain contained framework-facing service contracts and transport DTOs that accepted Eloquent models, Laravel requests/uploads, pagination collections and streamed responses. Those types are orchestration/integration concerns, not domain concepts. They now live under the owning capability's `Application/Contracts` or `Application/DTO` namespace until Steps 5–6 replace their framework types with ports and adapters.

This reclassification did not add net framework debt: the Step 3 dependency baseline contains 167 occurrences, down from the Step 2 total of 168. Domain debt itself is zero.

## Domain vocabulary introduced

Step 3 introduces validated value objects for concepts that previously travelled as ambiguous primitives, including:

- `MediaId`, `WorkspaceId`, `FolderId`;
- `UploadSessionId`, `DirectUploadSessionId`;
- `FileSize`, `StoragePath`, `MimeType`, `Sha256Checksum`;
- `PartNumber`, `ETag`;
- trust-context `MediaScanId`.

The direct-upload provider inspection result, multipart part DTO, trust scan target and storage usage model now use value objects internally. Framework/storage adapters translate primitive SDK/database values at the edge.

Step 4 builds lifecycle aggregates/state machines on this vocabulary rather than moving Eloquent models into Domain.
