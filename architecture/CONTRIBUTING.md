# Contributing

## Local quality checks

Install development dependencies, then run:

```bash
composer run architecture
composer run analyse
composer test
```

Laravel Pint is configured for contributors:

```bash
composer run format
composer run format:check
```

`composer run verify` runs the architecture gate, static analysis, and PHPUnit suite. Formatting remains an explicit contributor check so maintainers can review formatting-only changes separately from behavioral refactors.

## Compatibility

The GitHub Actions and CircleCI verification matrices cover the PHP/Laravel combinations documented in the README. New framework-major support must be added to `composer.json`, both CI configurations, and package migration tests together. CircleCI is verification-only; contributors must not add release/tag/Packagist publication commands there.

## Architecture

Canonical dependencies flow toward the domain:

```text
Http -> Application -> Domain
Infrastructure -------> Domain
```

Do not add HTTP response/route concerns to Application or Domain, and do not put Eloquent/filesystem/network implementations back into canonical Domain namespaces.

## Tests

Security, concurrency, persistence lifecycle, migration, or public API changes require regression tests. Prefer tests that demonstrate the invariant being protected rather than only checking a method was called.
