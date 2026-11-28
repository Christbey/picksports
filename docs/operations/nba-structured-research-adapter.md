# NBA structured research adapter

This is a source-scoped operating contract, not a current production or provider-coverage certificate. Historical roster counts, live canary event IDs and deployment packets are retained privately. See [release preparation](nba-adapter-release-candidate-2026-11-21.md) and [rollout gates](nba-research-rollout.md).

## Acquisition ordering

An NBA capture reserves a monotonically increasing team sequence under a short team-row lock before network acquisition. Completion reacquires that lock. Only the newest issued acquisition may update the current projection/cache/head. Older results retain immutable snapshots and receipts with `projection_applied=false`. A newer failed, in-flight or crashed attempt cannot be hidden by an older successful receipt; a later qualified acquisition is required for recovery. Receipt ID order is not source precedence.

Network acquisition stays outside that component's database lock. Team counters are independent; source-only assessment locks the participating teams in ID order. Current injury rows survive failure. Source-policy or acquisition changes invalidate an old assessment through its evidence identity. Reads do not acquire providers, write documents, queue work or buy reviews.

## Structured evidence admission

The adapter requires explicitly enabled, team-specific `structured_espn` source registration; a matching NBA event, season/type and distinct teams; complete or explicitly empty current acquisitions; known original observation clocks within the configured freshness policy; accepted normalizer versions; and matching current player/team/external identities. Pending, partial, failed, stale, unmapped, revoked or out-of-scope evidence remains unresolved without borrowing another team's clock or an older receipt.

Structured findings retain parser version, snapshot/receipt IDs, content/material hashes, entry references/JSON pointers, original status fields, provider and team-collection scope. They are ESPN-reported availability, not official NBA designations. Ambiguous status stays ambiguous; an empty collection does not certify a healthy roster. An injury-entry date is not the collection's publication timestamp. Unsupported lineup, rotation and rest/travel facts remain unavailable. Conflicting manual and structured material requires explicit reconciliation.

## Operation and recovery

Capture and stored adapter gates are separate. `NBA_INJURY_OBSERVATIONS_ENABLED` and `NBA_RESEARCH_SOURCE_REFRESH_ENABLED` default false in their source configuration. The latter admits the stored adapter and explicit `nba:refresh-injury-research <NBA_GAME_ID>` command; it does not schedule acquisition, auto-register sources or dispatch paid review. Shared and NBA research capability gates still apply.

Source registration and acquisition are mutations requiring an explicitly approved target and scope. Use verified current team/event IDs and the admitted provider host policy, never old canary IDs. Source-only assessment preserves forecast/approval ownership. Original paid-review validity is not renewed merely because a new receipt observes identical content.

Rollback disables only the intended NBA admission while retaining evidence/state tables and monotonic counters. Do not edit immutable receipts, reset acquisition generations or change NFL settings. Qualify current roster/source mapping, worker/freshness capacity, authentication/public disclosure, supported browser behavior and provider transport separately before deployment or activation.
