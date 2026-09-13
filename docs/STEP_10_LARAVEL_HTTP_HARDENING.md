# Step 10 — Laravel / HTTP Integration Hardening

The package HTTP surface is an adapter around the framework-neutral Application and Domain cores.

## Security defaults

Package routes are disabled by default. When enabled, protected routes must include Laravel `auth` middleware unless the host explicitly sets `routes.allow_unauthenticated_protected_routes=true` and supplies equivalent authorization at the edge.

Two named limiters are installed by the service provider:

- `tetranyble-storage-public`: defaults to 30 requests/minute, keyed by client IP and a hash of the public share token.
- `tetranyble-storage-authenticated`: defaults to 240 requests/minute, keyed by authenticated actor and client IP.

Share passwords are consumed only from POST requests; GET query-string passwords are ignored to keep credentials out of URLs, browser history and proxy logs.

Every package route passes through `AddStorageSecurityHeaders`, which sets `nosniff`, `no-referrer`, frame denial and a restrictive permissions policy. Download responders use Symfony's `makeDisposition()` rather than interpolating user-controlled filenames into response headers.

## Stable JSON error contract

Requests that expect JSON receive errors in this shape:

```json
{
  "success": false,
  "error": {
    "code": "resource_not_found",
    "message": "Resource not found."
  }
}
```

Validation failures use `validation_failed` and place field errors under `error.details.fields`. Domain/internal exception messages are not exposed by default. Unexpected JSON failures are reported through Laravel and returned as a generic `internal_error` with HTTP 500; the underlying exception message is never sent to the client.

Browser/download requests continue to receive normal HTTP exceptions/statuses so Laravel can render the host application's error surface.

## Composition root

`StorageServiceProvider` is intentionally thin. `StorageConfigurationValidator` owns fail-fast configuration invariants, `StorageBindings` owns adapter composition, and `StorageRateLimiters` owns HTTP limiter registration. Business decisions must not be added to the service provider. Controllers are also prohibited from issuing persistence queries directly; workspace-scoped route resources are resolved by `WorkspaceRouteResolver`.

## Configuration validation

Startup validation rejects unsafe or nonsensical combinations including:

- scanning enabled while processing is disabled;
- enabled protected routes without authentication, unless explicitly overridden;
- non-positive rate/retry/timeout limits;
- multipart direct-upload part sizes below 5 MiB;
- scanner classes that do not implement the scanner contract.

The architecture gate `composer architecture:http` prevents regressions to these boundaries.


## Step 10 verification snapshot

The dependency-free production gate currently verifies 441 PHP files. Domain and Application framework debt remain at zero, module dependencies remain below their Step-2 ceiling (91/92), and the complexity ratchet reports nine pre-existing oversized files with no growth. The HTTP gate is negative-tested to reject direct Eloquent query construction in package controllers.
