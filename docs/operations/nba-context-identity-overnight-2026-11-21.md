# NBA context identity fix and overnight acquisition audit — November 21, 2026

**The full-context recapture identity fix passes locally. Overnight production source coverage fails verification.** The candidate is based on deployed release `4df92d68` and remains undeployed on `codex/nba-context-content-identity`. No production settings, source scope, schedules, forecasts, approvals, paid reviews or accounting were changed. No live ESPN or OpenAI request was made in this pass. Analysis scheduler and publisher remain false.

## Context fix

`GameContextEvidence` previously included immutable packet IDs in the historical-team synthetic content hash through raw-boxscore `$refs`. Two identical raw packets could therefore invalidate reuse. The NBA-only producer now separates full provenance references from content references: verified stored game/provider-event identities and hashes of the raw response bodies determine the raw-history content identity. The normalized-stat branch retains stat/game/player identities and individual parsed minutes, rather than relying only on aggregate totals. Full snapshot IDs, evidence references and original observation times remain in immutable evidence and revalidation associations.

The producer version is **`nba-context-v2`**. Different packets containing identical bodies now yield the same material comparison, while the exact full evidence fingerprints still differ. Changed raw bodies, authority, event identity, parser/policy, coverage, approvals or forecast inputs retain their invalidation behavior. Unknown raw metadata changes are conservatively treated as changed content. No timestamp is refreshed without acquisition, and freshness/source approval checks are unchanged.

The end-to-end regression constructs two distinct complete raw-history/depth packets with new observation times, assesses both through `GameResearchService`, reconciles through the real coordinator and reads the current review through the normal application service. One fake-provider review is retained with two provenance associations, one generation, one immutable revision, unchanged original paid time/expiry/accounting and full references to the newer packet. A changed-body case correctly requires pending work without making another purchase. The identical case failed before the fix and passes afterward.

**281 tests / 1,784 assertions pass on both SQLite and disposable MySQL 8.4.6.** This includes NFL compatibility/ingestion, NBA observations/context/reuse, API presentation, migration safety and scheduler guards. Ten schedule-clock cases cover normal window boundaries and both DST transitions; seven manual acquisition cases cover overnight and repeated/skipped Central hours. These use HTTP/AI fakes and do not prove live overnight network or queue health. Scoped Pint and whitespace checks pass. No migration, API/frontend shape or permission change is required. The temporary MySQL database/server/data were removed after verification.

Versioning deliberately invalidates old v1 research applicability on a future deployment. Existing v1 paid reviews, sources and lineage must remain immutable; do not rewrite them to make them look like v2 reviews. A future production canary must assess fresh v2 evidence and establish an eligible v2 interpretation before checking cross-packet reuse. No additional paid production review was authorized or executed in this pass.

## What overnight verification found

At **7:21:54 p.m. Central**, the authorized production read-only audit inspected actual schedule definitions, 14 days of injury-command heartbeats and all existing structured NBA observation receipts:

- NBA injury cadence is `2,32 * * * *` with the existing **08:00–23:00 Central** filter. Normal last dispatch is **22:32**, next is **08:02**: a **9.5-hour dispatch gap**, exceeding the unchanged two-hour research freshness policy. Actual worker/network delays can make coverage worse; a scheduled minute is not a verified observation.
- **407 matching heartbeats**, including schedule- and worker-source records, contained **zero overnight records** (23:00–07:59 Central). A worker success here means the dispatcher command returned successfully; that command queues `FetchPlayerInjuries`. It is not proof that every team acquisition completed.
- The **20 structured receipts** available at audit time were from the existing recent daytime canary. They do not establish a historical overnight acquisition SLA. The current source admission window ends at **7:55:04 p.m. Central**, so it cannot authorize a midnight live trial. The expiry was not extended.
- There is **no scheduled `nba:capture-research-context` or `nba:refresh-injury-research` bridge**. These remain explicit manual commands. Shared analysis scheduler/publisher are false. Existing overnight scoreboard/game-detail/model jobs do not establish current approved injury/depth/context research coverage. Manual full/live pipeline definitions may include injuries, but no matching scheduled overnight acquisition was verified.

The manual observation path succeeds under simulated midnight, 03:32 and 07:32 clocks and both DST transitions when explicitly admitted by a fresh local test scope. This proves the action has no inherent daytime-only restriction. It does **not** close the production scheduling, source admission, provider availability, queue completion or context freshness gaps.

## Required source-only overnight trial

Keep automation off while reviewing this policy and candidate. The missing work is source-only orchestration, not permission to relax freshness or buy repeated AI reviews:

1. Define the coverage contract: continuous team health observations versus only upcoming pregame events. Prefer a bounded upcoming-game horizon, with an explicit empty-horizon skip; do not promise all-night two-hour coverage from daytime data. Review the horizon and cadence before implementation/activation.
2. Implement a separately disabled NBA-only acquisition/assessment bridge, retaining both shared analysis automation flags false. Admit only explicitly selected teams, approved sources and eligible future events within a reviewed, unexpired trial window. Do not silently renew the current window or approve new source registrations.
3. Acquire each team once per run and capture bounded event/depth/history packets as needed. Use existing sequence ordering, leases, size/reference/request limits and cache-origin timestamps. Fail closed on incomplete/mismapped/conflicting data; preserve original last-good validity. Do not invoke `RefreshGameAnalysis`, forecast generation, normalized box-score repair or the paid runner.
4. After actual acquisition completes, assess source-only research idempotently. Record per-team receipts and per-event packet/coverage health, queue wait, failures, scope denials and first/last verified observations. A dispatch/worker heartbeat must not be presented as successful complete team evidence.
5. Verify clock boundaries, DST, source failures, unchanged polls, concurrent/overtaken work, expired admission and empty horizons in nonproduction. Exercise the newly fixed cross-packet identity with an initial fake review; demonstrate zero additional paid work on identical contents and invalidation on changed contents. Preserve NFL timing, schemas and normal worker capacity.
6. After review, choose an explicit overnight trial window and future matchup/team scope, then verify at least one actual evening-to-morning cycle using genuine source timestamps and completion receipts. Inspect the largest successful-observation gaps and queue delay against the chosen SLA. Do not certify live overnight behavior solely from mocked clocks or a daytime provider response. Keep paid review/analysis automation off until those results are reviewed.

No new overnight flag or schedule was introduced by this fix. The proposed source-only bridge and real overnight trial remain open work; current production is not certified for unattended overnight research.

## Release and recovery

Review the clean candidate before promotion. Deploying this code requires no migration or configuration change. Preserve the existing NBA reuse/source settings, current two-team expiry and all NFL settings. Source applicability will correctly change once v2 is used; no existing review should be retroactively upgraded. After deployment, a bounded nonproduction or explicitly budgeted production v2 canary should compare two genuine context captures and retain original paid history. Do not assume v1 review 2 can be relabeled or reused across the parser-version change.

Code recovery is the prior `4df92d68` release; no data rollback is needed. If later reuse needs to stop, set only `NBA_REVIEW_PROVENANCE_REUSE_ENABLED=false` through the normal Cloud configuration deployment, leaving shared analysis automation false. Preserve raw packets, reports, reviews, associations and accounting.

[Redacted audit, readback, tests and file hashes](nba-context-identity-overnight-2026-11-21.json).
