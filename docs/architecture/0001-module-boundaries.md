# ADR 0001: Modular Laravel monolith and module boundaries

- Status: Accepted
- Date: 2026-08-12

## Context

PickSports serves Inertia/Vue, first-party mobile clients, and public API consumers. Separate backends would duplicate calculations and allow their contracts to drift. The existing code is organized mainly by framework layer and sport, so moving to explicit business modules must be incremental.

## Decision

PickSports will remain one Laravel application, one deployment unit, and one transactional database. New and migrated code will belong to these bounded modules:

- Identity: users, devices, authentication, subscriptions, and entitlements.
- Sports: shared sport contracts plus NFL, MLB, NBA, and other implementations.
- Events: canonical events, schedules, provider identities, and status.
- Markets: quotes, bookmakers, props, and line history.
- Predictions: outputs, explanations, trends, and signals.
- Betting: decisions, user bets, settlements, ROI, and CLV.
- MachineLearning: datasets, feature schemas, runs, artifacts, and evaluations.
- DeveloperPlatform: API consumers, credentials, quotas, usage, and webhooks.

Transport code is an adapter. API and Inertia controllers call the same application actions and queries. Those services return immutable typed DTOs; API resources and Inertia presenters only serialize DTOs and may not query models or calculate business results.

Modules may use another module through an application service, DTO, event, or explicit contract. They should not reach through a transport adapter or duplicate a calculation. Sport-specific models remain detail implementations while canonical event and prediction identities are introduced.

All schema changes use expand/backfill/compare/contract. A release may add nullable structures and dual-write, a queued or command-driven backfill populates them, comparison proves equivalence, and only a later release removes legacy structures. Destructive one-release migrations are prohibited.

## Consequences

- Web requests avoid the latency and failure modes of calling PickSports' own REST API.
- Mobile and public APIs share calculations with the web while retaining transport-specific serialization.
- Existing folders can migrate module by module; a repository-wide rewrite is not required.
- Module and resource architecture tests become release guardrails.
