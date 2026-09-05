# Public API v2 Changelog

All notable public API v2 contract changes are recorded here. Dates use UTC. Additive changes remain within v2; incompatible changes require the process in the [deprecation policy](deprecation-policy.md).

## 2026-08-12

### Added

- Developer credentials may access the entitlement-protected sandbox endpoint at `GET /api/v2/developer/sandbox`.
- Developer responses expose request, quota-limit, quota-remaining, quota-reset, and usage-unit headers.
- API errors use a stable error envelope and include a request identifier.
- Idempotency keys protect supported user-bet create and update requests from duplicate writes.
- User-bet prediction references use stable sport slugs instead of PHP model class names.
- The OpenAPI artifact declares first-party token, OAuth 2, and developer credential authentication schemes.

### Operational

- Immutable developer usage records can be aggregated into idempotent, provider-neutral billing meter batches. Batch creation does not contact a billing provider.
- Provider redistribution review status is tracked as an explicit launch-readiness inventory.

## Version 2.0.0 baseline

API v2 is the current versioned contract. Existing unversioned endpoints are outside this changelog unless a migration notice explicitly includes them.
