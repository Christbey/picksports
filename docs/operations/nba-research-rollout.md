# NBA research rollout procedure

This is the curated operating successor to historical rollout and canary evidence. Verify current environment identity and deployment through [production access](production-access.md); private deployment, snapshot and command identifiers are deliberately not distributed here. Historical observations do not establish today's flags, schema, source availability or workers.

## Release prerequisites

Review the exact candidate source and lockfiles, all pending migrations and staged settings, and a recoverable backup with an accepted restore owner and recovery age. The hosting deploy workflow may apply every pending migration; stop for unexpected schema or ledger drift. An existing table without its expected ledger entry needs reconciliation, not a blind rerun or destructive down. Coordinate compatible application, scheduler and queue runtimes.

Research work retains additive work-item, review-revision, source-document and assessment history. Verify tables, indexes and foreign keys before admission. A rollback retains those records and uses a reviewed compatible code/configuration release. Source-only evidence is separate from a scientific model or betting approval.

## Source-only admission

Register only explicitly reviewed team sources and resolve the current event and distinct team identities from the system. Structured ESPN availability needs current complete or explicitly empty team receipts, accepted observation clocks and matching roster identities; unsupported lineup, rotation or rest/travel coverage remains unavailable. Optional manual documents need exact grounded excerpts, publication and original observation times. Ingestion time cannot replace source observation.

`nba:refresh-injury-research <NBA_GAME_ID>` assesses stored evidence and does not acquire it or buy a review. Source acquisition commands can contact providers and require their own authorized bounded scope. Use [the structured adapter contract](nba-structured-research-adapter.md) and inspect command help/source before operating. Never copy historical game/team IDs into a new pilot.

## Separate review and automation decisions

Shared research capability, NBA research, shared prediction review, NBA review, acquisition and automation gates have separate meanings. Review ownership can change immediately after a compatible deployment enables its gates; it is not a harmless shadow setting. Preserve unrelated sport settings and shared provider budgets/circuit state. Dispatch or reservation does not prove a completed review.

Before enabling automation, qualify sustained source freshness, exact team/event scope, worker provisioning, effective timeouts and queue reservation leases, shared-provider headroom, budget accounting and alert ownership. An occasional manual canary does not establish these properties. Fresh-source observation and paid interpretation validity are distinct; repeated identical content cannot silently extend an old interpretation's validity.

## Deployment and rollback

Stage only the approved configuration changes, inspect unrelated staged settings and deploy the exact reviewed release through the hosting workflow. Confirm the deployed identity and effective flags afterward. One-off config-cache commands do not update every instance. No pinned rollback capability may be assumed from a generic deploy command.

Rollback closes new NBA admission using the gates this rollout owns, retains all source/assessment/review/work history and waits for owned in-flight attempts to settle. If another sport uses shared automation, disable only the NBA capability rather than stopping unrelated work. Do not purge shared queues, clear provider circuits, reset budgets/counters, destroy tables or backdate releases. Recheck existing NFL and nonselected-sport behavior and current source/reader contracts after any approved rollback.

## Overnight source bridge

`NBA_OVERNIGHT_SOURCE_BRIDGE_ENABLED` defaults to false. The scheduler dispatches a unique Redis `nba-sources` job at :12 and :42 between 23:00 and 07:59 America/Chicago during configured NBA season months. Provision and verify the dedicated worker before enabling the flag; scheduled dispatch does not establish acquisition completion.

`nba:refresh-overnight-sources --dry-run` inspects admission without acquisition, writes or dispatch. Acquisition additionally requires observation, research-adapter and research capabilities, an admitted unexpired scope of one or two distinct teams, approved structured sources, and matching canonical event identities. At most one scheduled or delayed game in the next eight hours is admitted. An empty upcoming-game set can support team-source health only. The bridge acquires source observations and performs descriptive assessment; it does not invoke paid review or enable analysis automation. Inspect actual result reasons and source receipts rather than treating a dispatcher heartbeat as fresh evidence.

The worker uses one attempt, a 600-second timeout and a 720-second unique lease. Reconcile these with effective queue reservation and worker settings before operation. This source-backed contract does not establish deployed worker provisioning, sustained freshness or provider headroom.
