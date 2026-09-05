# PickSports Public API v2 Operations

This directory is the operational companion to the generated [OpenAPI specification](../../openapi-v2.json). The OpenAPI document defines the HTTP surface; these documents define lifecycle and operating expectations.

- [Changelog](CHANGELOG.md)
- [Versioning and deprecation policy](deprecation-policy.md)
- [Service-level objectives](sla.md)
- [Sandbox guide](sandbox.md)

These documents describe API v2. Commercial agreements, provider terms, and incident-specific notices take precedence where applicable.

Operational launch checks include `php artisan developer-platform:check-redistribution-licenses`. It reads `config/provider-redistribution.php` and fails when a required provider's internal review status is not `confirmed`; the inventory is not a legal determination. Billing operations can prepare a deterministic batch with `php artisan developer-platform:build-meter-batch --from=... --to=...`. That command persists a pending batch and never contacts Stripe or another billing provider.
