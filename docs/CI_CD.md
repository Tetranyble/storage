# CI/CD and Packagist Releases

`tetranyble/storage` uses GitHub Actions for continuous integration and a gated release workflow for GitHub Releases and Packagist publication.

## Continuous integration

`.github/workflows/ci.yml` runs on pushes to `main`, pull requests targeting `main`, manual dispatches, and as a reusable workflow from the release pipeline.

The release CI includes:

- Composer metadata validation and dependency audit;
- architecture, domain, application, adapter, provider, CQRS, resilience, HTTP, reliability, release and complexity gates;
- PHPUnit and Larastan/PHPStan/Pint through `composer run production:gate`;
- PHP 8.2–8.5 and Laravel 12/13 compatibility;
- PostgreSQL 17 and MySQL 8.4 integration/concurrency tests;
- PostgreSQL/MySQL large-workspace query budgets and N+1 protection;
- MinIO S3-compatible direct/multipart provider contracts.

Do not publish a release from a red commit.

## Packagist model

Packagist does not receive an uploaded package binary from GitHub Actions. It indexes the Git repository and exposes Composer versions from Git tags.

The existing `tetranyble/storage` Packagist package is connected to `https://github.com/Tetranyble/storage` and has GitHub auto-update enabled. The release workflow therefore creates the version tag only **after** the complete CI workflow has passed. Packagist then discovers that tag through its GitHub integration.

The final release job polls Packagist's public Composer metadata and verifies both:

1. the requested version is visible; and
2. when Packagist supplies a source reference, it matches the exact Git commit that passed CI.

No Packagist API token is required for the normal pipeline.

## One-time GitHub repository settings

### 1. Protect `main`

In **Settings → Rules → Rulesets**, create a branch ruleset for the default branch (`main`). Recommended baseline:

- Require a pull request before merging. If this is currently a solo-maintainer repository, do not require another person's PR approval; the CI and release-environment gates remain the safety controls.
- Require the status check **`Required CI`** before merging. This is the single aggregate check in `.github/workflows/ci.yml`; it fails unless quality, the PHP/Laravel matrix, MySQL/PostgreSQL integration, query-performance/N+1 checks, and MinIO all succeed.
- Block force pushes.
- Restrict deletions of `main`.
- Require the branch to be up to date before merging if your contribution flow can tolerate the extra reruns.

Run CI once before configuring the required status check so GitHub has seen the `Required CI` check name.

### 2. Configure the `release` environment

Create **Settings → Environments → New environment → `release`**. The release workflow already declares `environment: release`; merely referencing the environment in YAML is not sufficient because GitHub can auto-create an environment with **no protection rules**.

Recommended environment protection:

- Add at least one required reviewer/maintainer.
- For a solo-maintainer repository, leave **Prevent self-review** disabled or the release will deadlock. With two or more release maintainers, enable Prevent self-review and require a different maintainer to approve.
- Under deployment branches/tags, choose **Selected branches and tags** and allow **Branch: `main`**. The workflow is intentionally dispatched from `main` and creates the tag only after approval and validation.
- For the strongest policy, disable administrator bypass of environment protection rules.

No Packagist secret is required while the existing GitHub auto-update integration remains enabled.

### 3. Make published `v*` tags immutable without blocking the release bot

Create a tag ruleset targeting `v*`, but with the current `GITHUB_TOKEN`-based release workflow **do not enable Restrict creations**. Restricting tag creation would require a separately authenticated bypass identity, such as a dedicated GitHub App or service-account token.

For the current workflow, enable:

- **Restrict updates** for `v*`;
- **Restrict deletions** for `v*`.

This lets the tested Release workflow create a new version tag once, while preventing a published tag from being moved or deleted later. That matches Packagist's stable-version immutability model.

If you later want to restrict creation of `v*` tags as well, first replace the built-in `GITHUB_TOKEN` for the tag-push step with a dedicated GitHub App installation token and add that App to the tag ruleset bypass list. Do not activate Restrict creations first or the current release workflow can fail at `git push origin "$TAG"`.

### 4. Workflow permissions

Keep repository default `GITHUB_TOKEN` permissions read-only under **Settings → Actions → General → Workflow permissions**. CI needs only `contents: read`; only the `publish` job in `release.yml` explicitly elevates itself to `contents: write` so it can create the tested tag and GitHub Release.

The release workflow is serialized with one concurrency group, so two maintainers cannot publish competing versions simultaneously. It also builds and validates source archives **before** pushing the irreversible public tag.

## Preparing a release

1. Merge the intended release commit to `main` and make sure normal CI is green.
2. Move the release notes out of `## [Unreleased]` in `CHANGELOG.md` into a dated heading, for example:

   ```markdown
   ## [3.0.0] - 2026-09-13
   ```

3. Keep an empty/new `## [Unreleased]` section above the released version.
4. Commit and merge that changelog update to `main` and wait for CI.
5. Open **Actions → Release → Run workflow** from `main`.
6. Enter the version **without** the `v` prefix, e.g. `3.0.0`.
7. Set `prerelease` when releasing an alpha/beta/RC.
8. Approve the `release` environment when prompted.

The workflow re-runs the complete reusable CI against the exact commit to be released. Only then does it create `v<VERSION>`.

## What the release workflow publishes

For a version such as `3.0.0`, the workflow:

1. runs the complete CI matrix;
2. validates SemVer and the matching changelog heading;
3. verifies `composer.json` and the source release gate;
4. refuses to overwrite an existing tag;
5. builds deterministic ZIP and tar.gz source archives from the exact tested SHA;
6. validates the packaged Composer metadata and generates `SHA256SUMS`;
7. creates annotated tag `v3.0.0` at the exact tested SHA as the first irreversible publication step;
8. creates the GitHub Release;
9. waits for Packagist to expose `tetranyble/storage` `3.0.0` at the same source SHA.

If Packagist synchronization fails, the GitHub release remains visible but the workflow finishes red. Inspect the Packagist/GitHub hook before retrying the verification; do not create another version merely to repair synchronization.

## Current major-version implication

The public Packagist release line before this re-architecture is `v2.1.0` and supports older Laravel generations. This release candidate intentionally supports Laravel 12/13 and contains public architectural/API changes. Under Semantic Versioning, it should be released on a **new major version line** rather than as a `2.x` update. Review `docs/PUBLIC_API_BASELINE.md` and the changelog before choosing the exact version.

## Optional hardened Packagist mode

Organizations that do not want Packagist to react to arbitrary Git tag pushes can disable the Packagist GitHub auto-update hook and call Packagist's authenticated update API after the release job. That mode requires a Packagist username and safe API token stored as GitHub environment secrets. The default project setup intentionally avoids this secret because the existing GitHub auto-update integration is already active.
