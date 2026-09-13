# Production Hardening / Scale v1 — Complete

Scale v1 is complete.

Implemented maturity areas:

1. ACL-aware SQL visibility and real PostgreSQL/MySQL contention tests.
2. Asynchronous MIME inspection, malware/quarantine policy and safe image processing.
3. Presigned single/multipart S3-compatible direct uploads with integrity verification and quota reservation.
4. Secret-safe observability plus read-only operational health checks.
5. Cursor query surfaces and large-workspace query-plan benchmark fixtures.
6. First-class derivatives, WebP/AVIF/EXIF processing, MinIO provider contracts, explicit retention and bounded bulk operations.

Phase 6 also removes pre-release compatibility baggage because the package has not been deployed: no alias shims, legacy manager, upgrade-only migrations, duplicate offset search/recent/activity APIs, or derivative mirror field.

Future work should be treated as normal product/version development rather than continuation of the structural-hardening roadmap.
