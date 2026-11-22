# NBA source refresh implementation plan

Updated November 21, 2026. **Use existing structured injury data first; PDF handling is optional.** This replaces the November 20 PDF-first proposal. The [structured feed audit](nba-structured-injury-feed-audit.md) records deployed code and read-only production evidence. NBA research/manual AI review are live; shared analysis scheduling and publisher dispatch remain off until the replacement flow is verified.

The NBA timestamp/snapshot foundation, acquisition ordering, structured adapter and identical-content review reuse are deployed in `4df92d68`. The limited BOS/SAC injury-observation reuse canary passed with one paid review and no second purchase; source admission retains its original November 21, 7:55:04 p.m. Central expiry. Shared analysis automation remains off. The full-context `nba-context-v2` identity fix is now verified locally but not deployed. [The context identity and overnight audit](nba-context-identity-overnight-2026-11-21.md) records the new candidate and the failed overnight coverage gate. NFL and provider schedules remain unchanged.

## What replaces the official report source?

Replace the routine **application input** with our existing ESPN JSON injury feed. Preserve the source identity: **ESPN-reported NBA availability**, not “official NBA injury report.” A source's format, publisher, coverage and authority are separate attributes.

The official NBA PDF remains an optional comparison/fallback when game-specific confirmation is needed or sources disagree. It is not a prerequisite for ordinary structured research. If league-official provenance is a product requirement, we must separately verify an NBA-origin or appropriately authorized structured feed with suitable fields and timing. That equivalent has not been established by this audit; a vendor's JSON injury list or a library that parses PDFs is not proof of it. See the [audit's provider evaluation](nba-structured-injury-feed-audit.md).

| Evidence | Application label and permitted use |
| --- | --- |
| Validated ESPN injury collection | ESPN-reported team availability as of the verified observation; raw statuses retained |
| Reviewed official NBA report | Official report evidence for the explicitly matched date/team/event |
| Other structured provider | Its actual publisher/contract; independently validate authority, latency and status semantics |
| Missing, stale, incomplete or conflicting evidence | Explicit limitation; no fabricated healthy roster or automatic approval |

`Day-To-Day` must not become a confirmed NBA `Questionable` designation for a particular game. A complete empty ESPN collection means no injuries reported by that provider, not proof every player is available. Keep injury/illness, rest and roster assignment distinct. Do not treat two feeds sharing an upstream source as independent corroboration.

## Target flow

**Existing ESPN acquisition → verified NBA team observation/snapshot → structured research adapter → event assessment → separately gated AI review.**

Reuse the existing ingestion requests. Do not create a second all-team poller merely to populate research. The main work is to capture reliable completion/provenance around acquisition and bridge it into research. Existing NBA/NFL ingestion continues as configured while new research automation stays off.

The bridge must call the store/assessment path directly. [RefreshGameAnalysis](../../app/Jobs/Predictions/RefreshGameAnalysis.php) immediately invokes the paid runner when review is enabled, so source-only refresh must not dispatch that job.

## 1. Capture trustworthy NBA team observations

**Verification update:** disposable MySQL 8.4.6 migrations/constraints, 48 focused regression tests and a one-team live ESPN acquisition canary passed on November 21. See the [verification record](nba-injury-observation-implementation.md#disposable-mysql-and-real-acquisition-verification--november-21). This does not activate production or complete the research adapter/concurrency gates.

Current path: [NBA command](../../app/Console/Commands/ESPN/NBA/SyncPlayerInjuriesCommand.php) → [queued job](../../app/Jobs/ESPN/NBA/FetchPlayerInjuries.php) → [NBA action](../../app/Actions/ESPN/NBA/SyncPlayerInjuries.php) / [shared action](../../app/Actions/ESPN/AbstractSyncPlayerInjuries.php). The deployed path still has mutable per-player rows. The local opt-in implementation now adds immutable team snapshots, entries and completed-observation receipts; production remains on the existing path.

Add NBA-scoped, append-only team snapshots and small completed-observation receipts. Reuse [ProviderSourceStorage](../../app/Services/ProviderData/ProviderSourceStorage.php) for private raw JSON archival and content deduplication where appropriate. Inspect actual FK types and use additive migrations; preserve existing manual research documents and current injury projections.

Each observation needs:

- Provider, source URL/type, internal/ESPN team IDs and collection scope.
- Fetch start/completion, real network observation, cache-hit/original-fetch metadata and provider time fields with their original meaning/offset.
- Immutable payload hash/archive reference, normalizer/schema version, expected/received/resolved counts and pagination completeness.
- Complete-nonempty, complete-empty, partial or failed outcome, including unmapped players and unresolved references.
- A stable material-claims hash separate from poll/observation identity.

Archive all provider entries, including athletes not mapped into the current roster. Keep their external IDs and mark identity coverage partial. A failed team must not borrow another team's latest successful timestamp. A valid zero-row response must have a receipt; row maxima cannot represent empty collections.

The current client caches payloads without exposing the original network fetch time. Add compatible NBA metadata handling so a cache read does not pretend a new upstream observation occurred. Keep last poll, last verified observation and last material status change distinct.

## 2. Fix the NBA data contract before relying on it

The audit confirmed a timezone bug: raw `2026-11-21T04:06Z` becomes SQL `2026-11-21 04:06:00` and is later read as Central time. Preserve the instant and normalize SQL storage to application timezone. Keep provider entry date, provider update time, collection observation and research assessment time separate. The sampled `source_updated_at` values came from `date` because `lastUpdated` was absent; that fallback is not a verified report publication time.

Implement NBA-scoped validation for:

- Recognized collection shape and complete pagination, including the configured `limit=100`, all required references and any roster fallback.
- Correct source/team/player identities; approved reference hosts/paths and safe resolution without cross-team or cross-sport assignment.
- Malformed arrays, partial pages, unresolved references, unknown athletes and transport failures. None may retire missing current injuries as though a complete empty feed had been received.
- Raw and normalized status/reason fields. Preserve ambiguity; `is_active` is not sufficient evidence that a player will miss the selected game.
- Mixed offsets, DST, future times and cache age. Do not bulk-correct history by subtracting six hours; only repair rows whose original timezone-aware payload proves the instant, otherwise retain uncertainty.

Prefer an NBA-specific action/client/adapter or backward-compatible hook with unchanged defaults. The shared base has pending unrelated workspace changes; use a clean deployed baseline and retain NFL's activity/retirement hooks, snapshots and timestamp behavior. Do not promote those working changes as part of NBA source work.

## 3. Bridge verified JSON into research

Add a versioned NBA structured evidence adapter. Bind a verified team observation to upcoming events using internal team/event identities and authoritative kickoff. Do not imply the provider itself filed a game-specific report when its collection is team-wide.

Register distinct ESPN source keys/provider identity. Preserve the existing official-report source/document records and their original publisher; never repurpose them as ESPN entries.

The adapter should produce availability findings with snapshot/entry IDs, raw-payload hash, JSON pointers, provider label, observation time, raw status and normalized meaning. The current [ResearchDocumentStore](../../app/Services/Research/ResearchDocumentStore.php) is an exact-excerpt interface; do not manufacture a prose sentence and present it as an original source quotation. Extend the evidence contract compatibly to represent structured fields with traceable references and a real parser version, retaining existing manual excerpts.

Carry publisher and scope through the evidence builder, [presentation allowlist](../../app/Services/Api/V2/GameResearchPresentation.php), API schemas/types and admin component. Existing URL/title fields alone must not imply official provenance. Where a live JSON collection has no publication timestamp, retain `published_at=null` and use its verified observation contract; do not substitute injury date or sync time as publication.

Use [GameResearchService::assess](../../app/Services/Research/GameResearchService.php) for descriptive assessment. Keep lineup, rotation and rest/travel unavailable unless separately grounded. Source collection/research must not directly change model weights, forecasts or betting approvals; normal provider updates may continue through the existing prediction pipeline with exact revision lineage.

Research freshness must use a verified successful team observation and upstream freshness constraints, not an old injury-onset date or table-wide `MAX(updated_at)`. A persistent injury with an old entry date may still be newly confirmed by a complete current collection. Conversely, a recent row update or dispatch heartbeat alone does not establish completeness. Retain the current two-hour research policy until measured evidence supports an explicit change; the injury API's 48-hour default is not that policy.

On upstream error, retain last-good evidence only through its original validity. New checks cannot extend fixed publication times or resurrect stale evidence. Source revocation, event changes, incomplete identity and conflicting statuses must remain visible.

## 4. Prevent duplicate paid interpretations

NBA-only identical-content reuse is deployed and its limited injury-observation canary passed. See [the implementation contract](nba-review-provenance-reuse.md). The new [full-context identity fix and overnight audit](nba-context-identity-overnight-2026-11-21.md) passes cross-packet reuse locally; production promotion remains pending. Full provenance and the existing exact fingerprint remain authoritative; broad claim-only deduplication is deferred. Analysis automation remains off.

Current [StoredGameEvidence](../../app/Services/Research/StoredGameEvidence.php) and [ReviewFingerprint](../../app/Services/Predictions/Review/ReviewFingerprint.php) include document/provenance identities and times. New receipts can therefore invalidate paid review even with unchanged material facts.

Separate versioned material identity from provenance:

| Identity | Purpose |
| --- | --- |
| Material | Event/team/player, approved authority, normalized status/reason, coverage, relevant forecast/decision and parser/policy meaning |
| Provenance | Raw source, receipt/snapshot/document IDs, URL, original timestamps, archival hashes and completeness validation |

Unchanged polls must record health without buying reviews. Reuse across a newly verified observation requires unchanged material facts, current approved evidence, unchanged forecast/decision/execution inputs and an unexpired original interpretation. A material status, authority, coverage, quote/approval or forecast change must update applicability appropriately.

Preserve paid timestamp, generation and accounting. If a new provenance association is needed, make explicit revalidation/reuse lineage; the current one-revision-per-generation uniqueness must not be bypassed by rewriting history or inventing another paid call. Scope new fingerprint semantics to NBA so NFL compatibility is preserved.

## 5. Scheduling, health and application flow

The [NBA overnight source bridge candidate](nba-overnight-source-bridge-rc-2026-11-21.md) is now implemented and verified locally together with the context identity fix. `NBA_OVERNIGHT_SOURCE_BRIDGE_ENABLED=false` is independent of paid analysis, uses a dedicated `nba-sources` worker, retains explicit two-team/expiry/source approval, and records actual acquisition/assessment completion. It is not deployed or activated. The original live overnight coverage gate remains open until a reviewed real cycle succeeds.

The existing NBA provider schedule runs at minute 2/32 within 08:00–23:00 Central. Its last normal dispatch is 22:32, then 08:02. The live 14-day audit found zero overnight injury-command heartbeats and no context/assessment bridge. Overnight coverage therefore fails verification. Define and verify the [bounded source-only trial](nba-context-identity-overnight-2026-11-21.md) before promising two-hour coverage. Leave NFL schedules unchanged.

Proposed `NBA_RESEARCH_SOURCE_REFRESH_ENABLED=false` controls the new verified-observation-to-assessment workflow independently of paid analysis. **Implemented and deployed, default off; production admission is limited by the existing two-team/expiry gate. The explicit command is `nba:refresh-injury-research`. No scheduler/listener was added.** Enable source-only operation first; both existing analysis automation flags remain false.

- Persist completion after actual team acquisition, not when the dispatch command returns success. Overall job success cannot hide partial team failures.
- Process new verified observations after commit with idempotent, bounded batches and overlap/lease protection. Reuse workers only after capacity review; a queue must have a consumer before it is enabled.
- Monitor per-team last verified observation, cache origin, parse/mapping failures, incomplete/empty outcomes, coverage expiry, changed claims, queue age and existing NFL heartbeat/latency.
- Show publisher, team scope, last observation, source limitations, expiry and review status consistently in the application. Reads never fetch or purchase research.
- Assign an acquisition/incident owner and configured health-reporting path. Do not add notifications to unapproved destinations.

## Optional official-report/PDF path

Keep the [existing packet acceptance checklist](nba-rollout-source-checklist.md) for reviewed official reports. It retains original PDF bytes/hash, extraction version, visual review, exact team/event excerpts and publication/observation times. No automated PDF worker or extraction dependency is required for the primary structured path.

When official evidence is used, keep its publisher and scope distinct. Reconcile discrepancies explicitly; do not silently relabel ESPN data official or treat an older official publication as fresher than it is. PDF extraction can be added later behind its own adapter if automatic official corroboration is worth the operational cost.

The earlier pilot packet expires November 21 at 12:45 a.m. Central. It is historical evidence after that point; this audit does not refresh it or reset its timestamps.

## Delivery order and verification gates

1. **NBA acquisition contract and receipts:** scoped timestamp correction, complete collection outcomes, raw archival, empty/unmapped evidence and cache metadata. Test malformed/partial responses cannot remove valid entries.
2. **Structured research adapter:** provider labeling, event scope, fresh/expired/conflicting evidence and correct source references. Compare fixture output and a limited real stored observation without paid calls.
3. **Material-change/revalidation logic:** unchanged observation produces no paid work; changed status produces only the required bounded work; original paid validity and ledger remain immutable.
4. **Source-only trial:** inspect representative team observations including valid empty, unchanged and incomplete cases. Prove overnight/pregame policy, per-team freshness, worker capacity and healthy NFL behavior. Keep analysis scheduler/publisher off.
5. **Separate review automation decision:** only after the preceding gates pass, run a limited canary and consider the existing scheduler/publisher flags. Preserve default $5 application / $1 NBA / $0.25 event admission, attempt reserve and shared provider circuit.

Required regression coverage: UTC/Eastern/Central and DST; actual kickoff admission cutoff; pagination/reference failures; valid empty versus unavailable; unknown player mapping; status ambiguity; immutable observations; cache timestamps; stale-team/global-max masking; repeated identical polls; provider conflict; unchanged forecast/approval around research/review; source revocation; and original NFL API/writer/snapshot/schedule behavior. JSON syntax alone is not source validation.

## State and recovery

User direction remains: **automation off until the structured source flow is verified**. Existing ingestion schedules are not being stopped or altered by this plan. The current manual NBA research/review pilot remains as recorded in the [activation report](nba-activation-2026-11-20.md).

If future source automation fails, disable its NBA-specific gate, preserve receipts/raw archives/documents/assessments/revisions/accounting, and let valid last-good evidence expire naturally. Do not drop tables, restore production as routine feature rollback, purge shared queues/circuits, reset budgets or change old NFL settings. Follow [rollout/rollback](nba-research-rollout.md) for separately disabling NBA research/review.
