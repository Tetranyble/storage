# Security Policy

## Supported versions

Security fixes are provided for the latest released major/minor line of `tetranyble/storage`. The package currently targets Laravel 12 and 13 on supported PHP versions allowed by `composer.json`.

## Reporting a vulnerability

Please do **not** open a public issue containing exploit details, credentials, private URLs, access tokens, or customer data.

Report the vulnerability privately to the package maintainers using the private security-reporting channel for the repository or organization where this package is published. Include:

- the affected package version or commit;
- the vulnerable endpoint/service and required permissions;
- reproducible steps or a minimal proof of concept;
- the expected vs. actual security boundary;
- any suggested remediation, if known.

Until a fix is released, avoid publishing exploit details that would make active abuse easier.

## Security-sensitive areas

Changes to the following areas should always include regression tests:

- workspace/resource authorization;
- public sharing and download limits;
- remote URL validation / SSRF controls;
- storage quota reservation;
- resumable-upload ownership and session uniqueness;
- object-storage/database compensation;
- polymorphic model reconstruction;
- cloud-provider credential handling.

## HTTP boundary defaults

When package routes are enabled, protected routes fail closed unless authentication middleware is present (or an explicit custom-authorization opt-out is configured). Public share and authenticated package routes have separate rate limiters. Package responses add no-referrer/no-sniff/frame/permissions headers, and JSON errors use stable public messages rather than leaking internal exception text.

Hosts that replace `routes.middleware`, `routes.public_middleware`, or package controllers are responsible for preserving equivalent authorization and transport security. Keep public-share rate limiting enabled unless an upstream gateway enforces a stricter policy.
