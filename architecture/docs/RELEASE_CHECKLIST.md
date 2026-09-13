# Release Checklist

A stable release is allowed only after the repository and CI are green at the same commit that will be tagged.

## Mandatory automated gates

```bash
composer validate --strict
composer audit
composer run production:gate
composer benchmark:queries   # run against PostgreSQL and MySQL
```

CI must also pass the supported PHP/Laravel compatibility matrix, PostgreSQL/MySQL integration jobs, cross-process concurrency tests and the MinIO multipart provider contract.

## Security and configuration

- Keep package routes disabled by default.
- Keep private-network blocking enabled for remote imports.
- Do not enable unauthenticated protected routes without host-owned authorization middleware.
- If virus scanning is enabled, verify the scanner binary/runtime and private quarantine storage in the deployment environment.
- Verify production object-store credentials through secret management; never commit them to this repository.
- Run `php artisan storage:health --strict` after deployment and before directing traffic to package HTTP endpoints.

## Database and operations

- Install from the canonical fresh migrations on both PostgreSQL and MySQL in CI.
- Confirm the host workspace/user models use integer primary keys as required by the package schema.
- Schedule `storage:process-media` and `storage:cleanup-orphans` at least once per minute when asynchronous processing is enabled.
- Review retention in dry-run mode before enabling destructive retention.
- Confirm queue visibility outlives execution: `retry_after > STORAGE_PROCESSING_TIMEOUT` for queue drivers exposing `retry_after`, with equivalent visibility configuration for provider-managed queues.


## Automated publication

- Do **not** push the public version tag before the release CI has passed; Packagist auto-update can index it immediately.
- Publish through **Actions → Release** from `main`. The workflow reuses the complete CI matrix, then creates the annotated tag and GitHub Release.
- Configure the GitHub `release` environment with a required reviewer before the first production publication.
- After the tag is created, the workflow must verify that Packagist exposes the same semantic version at the exact tested commit.
- See `docs/CI_CD.md` for branch/tag protection and operational details.

## Release hygiene

- Update `CHANGELOG.md` and move the intended changes from `Unreleased` to the release version/date.
- Review `docs/PUBLIC_API_BASELINE.md` for deliberate breaking changes.
- Use SemVer: breaking public HTTP/facade/config/schema changes require a major release after 1.0.
- Let `.github/workflows/release.yml` create the tag only after its reusable full-CI job passes.
- Verify the built archive contains no credentials, `.env`, logs, local databases, caches, generated coverage, IDE metadata or nested release archives.
