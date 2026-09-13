# CircleCI verification

CircleCI is the package's secondary, verification-only CI provider. It exists so the full release-critical test matrix can continue to run when GitHub Actions is unavailable, while GitHub Actions remains the **only** workflow allowed to create version tags, GitHub Releases, or publish/verify Packagist releases.

## One-time setup

1. Sign in to CircleCI and connect the GitHub organization/account that owns `Tetranyble/storage`.
2. Install/authorize the CircleCI GitHub App for the public `Tetranyble/storage` repository.
3. In CircleCI, create/set up the project from that repository and choose the committed `.circleci/config.yml` as the configuration source.
4. In CircleCI's config editor, enable **config next** and validate `.circleci/config.yml` before the September 21, 2026 config-compiler changes. With the current CircleCI CLI, the equivalent is `circleci config validate .circleci/config.yml --next`.
5. Trigger the first pipeline from `main` or push a commit.
6. After the first successful run, GitHub will have seen CircleCI's checks. If GitHub Actions is still blocked by the account billing lock, use the aggregate **CircleCI Required CI** check as the branch-protection CI requirement until GitHub Actions is restored.

No project secret is required for the current CircleCI configuration. Database and MinIO credentials in the file are ephemeral test-only values for service containers.

Do not add GitHub PATs, Packagist tokens, release credentials, `git push`, tag creation, or release commands to CircleCI. `composer run architecture:circleci` enforces this policy in source.

## What it runs

CircleCI mirrors the release-critical verification lanes:

- the complete `composer run production:gate` on PHP 8.5 / Laravel 13;
- Composer metadata validation and locked dependency audit;
- PHP 8.2, 8.3, 8.4, and 8.5 against Laravel 12 where supported;
- PHP 8.3, 8.4, and 8.5 against Laravel 13;
- PostgreSQL 17 and MySQL 8.4 integration/concurrency suites against Laravel 12 and 13;
- PostgreSQL/MySQL large-workspace query budgets and N+1 checks;
- MinIO-backed S3/direct/multipart provider contract tests.

The final **CircleCI Required CI** job depends on every release-critical CircleCI lane. If any upstream lane fails, the workflow does not reach a successful aggregate result.

## Open-source usage

Keep the repository public and the CircleCI organization on the Free plan to qualify for CircleCI's Linux open-source credit allocation. The project intentionally uses ordinary Docker resource classes and does not enable paid Docker layer caching or macOS/Windows execution.

## Release authority

A green CircleCI build proves verification only. It is **not** permission to bypass the protected GitHub release workflow. Once GitHub Actions is available again, the stable release remains:

`main` → GitHub `Required CI` → protected `release` environment → immutable version tag → GitHub Release → Packagist verification.
