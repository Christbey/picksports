# PickSports Public API v2 Operations

This directory is the operational companion to the generated [OpenAPI specification](../../openapi-v2.json). The OpenAPI document defines the HTTP surface; these documents define lifecycle and operating expectations.

- [Changelog](CHANGELOG.md)
- [Versioning and deprecation policy](deprecation-policy.md)
- [Service-level objectives](sla.md)
- [Sandbox guide](sandbox.md)

These documents describe API v2. Commercial agreements, provider terms, and incident-specific notices take precedence where applicable.

Operational launch checks include `php artisan developer-platform:check-redistribution-licenses`. It reads `config/provider-redistribution.php` and fails when a required provider's internal review status is not `confirmed`; the inventory is not a legal determination. Billing operations can prepare a deterministic batch with `php artisan developer-platform:build-meter-batch --from=... --to=...`. That command persists a pending batch and never contacts Stripe or another billing provider.

Developer webhook endpoint issuance, signing, outbox and delivery-state classes are local foundations. No application delivery worker or HTTP sender is wired to them in the reviewed source. They do not establish a supported outbound delivery service. External meter export is also intentionally unbound. Launching either integration requires a separate complete transport, recovery, security and provider acceptance pass; see the [boundary review](../../contracts/developer-platform-boundaries.md).


## Pending meter batch persistence

`developer-platform:build-meter-batch` prepares a local pending batch; it does not export usage, charge an account, or contact Stripe or another billing provider. The candidate builder stages the requested parent totals and each organization/product item before model callbacks. A cancelled item save, rewritten planned value, missing item, or later observer changing an earlier item causes refusal and rolls back this new batch and its items. Before acknowledging the batch, it verifies the staged parent fields and the complete persisted item membership, owners, counts, units and dimensions inside the existing transaction.

Rebuilding the same meter code and half-open UTC period preserves its existing batch and items. Late-arriving usage does not silently revise a prior batch. This persistence guard does not retrospectively certify or repair historical batches, and does not change billing policy or bind an external export adapter.

The scoped controls exercise the actual command and builder with synthetic usage: cancelled parent/item saves, rewritten child units, a later observer modifying an earlier item, valid aggregation, and immutable replay. Billing export is forbidden by the test adapter and HTTP requests are prevented. Portable functional checks and after-only disposable-MySQL qualification are separate source-bound evidence; they do not certify production billing, concurrent writers, every unspecified export field, or arbitrary observer side effects on a foreign connection. Review the current qualification receipt before deploying the candidate.
