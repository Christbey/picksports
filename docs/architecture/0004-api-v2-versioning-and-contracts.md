# ADR 0004: `/api/v2` is the shared versioned product API

- Status: Accepted
- Date: 2026-08-12

## Context

PickSports needs stable contracts for Swift, Kotlin, and public subscribers while continuing to serve an Inertia web application efficiently. A separate mobile or public implementation would duplicate queries and calculations.

## Decision

`/api/v2` is the single REST implementation for first-party mobile and public API clients. The Inertia web application does not call it over HTTP; Inertia and API controllers call the same application actions and typed read models.

Every operation has a precise committed OpenAPI 3.1 schema. CI regenerates the document, fails on an uncommitted diff, and compiles generated TypeScript, Swift, and Kotlin clients. Response resources and presenters serialize supplied DTOs only and must not query the database.

The v2 contract standardizes:

- a request ID on every response and in the error envelope;
- one error shape with stable machine-readable codes and field errors;
- ISO 8601 timestamps, explicit time zones, JSON booleans, string decimals where precision matters, documented enums, and consistent cursor or page metadata;
- endpoint and principal rate limits with quota headers;
- idempotency keys for retryable writes, scoped to the authenticated principal and operation;
- durable string public identifiers instead of exposing table-local numeric identities.

Compatible additions may ship within v2. Removing or changing a field, meaning, enum, authentication requirement, or status behavior requires a new version or a documented deprecation window. Legacy v1 and old Inertia payloads are instrumented, migrated by endpoint family, compared side by side, and removed only after measured zero use.

## Consequences

- One calculation path serves all interfaces without forcing web traffic through HTTP.
- Generated clients and fixtures detect accidental contract drift before release.
- Public API packaging, quotas, and premium fields remain policy concerns rather than forks of sports logic.
- Current generic OpenAPI response schemas must be tightened incrementally until the document is the exact contract authority.
