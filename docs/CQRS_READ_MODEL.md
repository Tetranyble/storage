# Step 8 — CQRS Read Model

Step 8 makes the package's existing command/read split explicit without introducing event sourcing or a second database.

## Boundary

Mutation use cases continue through Application ports and Domain lifecycle rules. Workspace reads use the framework-neutral `WorkspaceReadModel` Application port and immutable query DTOs:

- `BrowseWorkspace`
- `TrashWorkspace`
- `StarredWorkspace`
- `SharedWithMe`
- `MediaVersions`
- `SearchWorkspace`
- `RecentWorkspace`
- `ActivityWorkspace`

The Laravel HTTP controller depends only on that read port. It does not depend on Eloquent query services.

## Eloquent read side

Each query has a dedicated handler under `Modules/Workspace/Infrastructure/ReadModel/Eloquent/Handlers`. Handlers may intentionally use Eloquent, joins, subqueries and cursor pagination because they are read adapters, not domain services.

Reusable read-side infrastructure is isolated in:

- `WorkspaceReadProjector` — maps Eloquent rows to framework-neutral `FileView`, `FolderView` and `ActivityView` DTOs;
- `ReadPagination` — bounded page sizes, cursor decoding and response pagination metadata;
- `EloquentReadResources` — validates opaque Application resource handles at the adapter boundary.

ACL predicates are still applied before pagination. Search/recent/activity continue to use stable cursor pagination without SQL OFFSET.

## Compatibility

`WorkspaceFileQueryService` remains as a compatibility adapter for existing package consumers and tests. It contains no SQL/Eloquent query composition; its legacy methods translate arguments to the new query DTOs and delegate to handlers. It shrank from 739 lines to 74 lines and is capped by `scripts/check-read-model-cqrs.php` at 150 lines.

New code should inject `WorkspaceReadModel` instead of `WorkspaceFileQueryService`.

## Executable rule

`composer run architecture:read-model` verifies that:

1. the read port and all eight query DTOs are framework-neutral;
2. all eight Eloquent handlers exist;
3. HTTP depends on the Application read port rather than the concrete adapter;
4. persistence composition cannot drift back into `WorkspaceFileQueryService`;
5. the compatibility adapter remains small.

The old 739-line complexity allowance has been removed. All remaining oversized baselines were also lowered to their current line counts so prior shrinkage cannot regress.
