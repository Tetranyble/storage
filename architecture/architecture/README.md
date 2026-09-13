# Architecture Gates and Ratchets

This directory contains executable architecture metadata. Baselines record **temporary debt**, not approved design.

- `modules.json` is the authoritative capability catalog introduced in Step 2.
- `module-dependency-baseline.json` freezes the transitional cross-capability graph. Existing edges may disappear; new edges fail the gate unless an explicit architecture decision changes the graph.
- `dependency-baseline.json` records remaining framework/concrete-adapter leaks in Application. Domain debt is zero as of Step 3; counts may only decrease.
- `scripts/check-domain-purity.php` is a zero-tolerance gate for Domain framework/layer leaks; it has no grandfathered exceptions.
- `complexity-baseline.json` records source files already above the 350-line budget. Those files may only shrink; new/untracked source files must remain at or below 350 lines.

Run `composer run architecture` to enforce all structural gates. Do not update a baseline upward simply to make CI green. A baseline change is valid only when debt is removed or an architecture decision intentionally changes a boundary.

Step 10 adds `scripts/check-http-integration.php`. It keeps the Laravel service provider thin, requires stable JSON error translation, fail-closed protected-route configuration, package rate limits, security headers, persistence-free route resolution across package controllers, and safe `Content-Disposition` construction.
