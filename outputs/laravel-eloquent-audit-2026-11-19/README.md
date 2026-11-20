# Laravel and Eloquent cleanup checklist

Implementation and final review: November 19, 2026. This README is the completion tracker for C01–C45. Supporting reports retain detailed locations and caveats. The audit covers broad source patterns; runtime costs and production incidents were not measured. All 45 IDs are addressed in the shared working tree. An independent multi-agent final review rechecked the implementation, relationship map and completion evidence; see the [final review](final-review.md). Completion covers local implementation or an explicit retained disposition; production schema/data and deployment were not verified or changed.

Eloquent is already the normal persistence layer. Prioritize atomic ingestion, consistent contracts, bounded queries, and explicit calculation/presentation inputs. Preserve ordinary PHP math, simulations, useful immutable data objects, reporting SQL, and batching that saves queries.

Required forecast/live/delivery prerequisites were committed separately as `1c8b7995`; the cleanup and this completed checklist follow together. The selected final tree passed 711 regression tests, including all architecture checks. Unrelated pending work remains outside these commits.

Release integration preserves newer production-main behavior and revalidates the cleanup in an isolated worktree; see [release validation](release-validation.md).

## Tracking rules

- Keep IDs stable. Do not delete or renumber completed, combined, or retained items.
- Mark an ID `[x]` only when its completion criteria are met and the relevant checks pass. Add a short completion note beneath it with the date, commit or PR when available, and validation performed.
- A partial fix leaves the parent ID unchecked. Track remaining subwork beneath that ID. Supporting report numbers and S identifiers are evidence references, not separate completion IDs.
- If review proves a change unnecessary, keep its ID and record the evidence and disposition as `Retained` or `Not applicable`. A checkbox alone must never imply code was changed. Optional review items may close with a documented retention decision under their criteria.
- Combine implementation when sensible, but close each affected ID against its own criteria. C41 guides relationship work and C43 precedes constraint changes; C04 precedes C05; C01 precedes bulk-write changes in C06. Resolve C02/C03 contracts before changing mapping ownership. C18, C19, C24, and C37 have separate scopes.
- Preserve publication/release gates, permission omission, point-in-time cutoffs, source completeness, null semantics, decimal precision, observers/events, and delivery locks/deduplication.

Checked IDs below record implemented and validated work; unchecked IDs remain open or partially implemented. P1 is data integrity, P2 is substantive query/contract/boundary work, and P3 is maintainability or measurement-led improvement. Priority does not establish a production incident.

## Cleanup checklist

- [x] **C01 — P1.** Validate and atomically replace imported stats and plays; retain prior complete data on failure. CFB overrides already have separate safeguards.

  Evidence: Models 1 ([models and ingestion](models-and-ingestion.md)).

  Done when: Every listed non-CFB player-stat, team-stat and play replacement path preserves existing complete rows on rejected/incomplete payloads and injected write failure; valid replacement and required model events still work.

  Completed November 19, 2026: Implemented staged, game-locked transactional replacements for the listed player-stat/play imports and the analogous shared basketball/NFL/MLB team-stat handlers. Invalid/incomplete feeds preserve prior rows; duplicate-athlete processed-count compatibility is retained. Validation: 254 provider-action tests (1,103 assertions) and 98 focused atomicity tests (367 assertions). See [implementation validation](implementation-validation.md).

- [x] **C02 — P2 contract review.** Decide and reconcile the NFL list/detail starts_at contract and duplicated game field definitions; no client defect is established.

  Evidence: HTTP 1 ([HTTP and contracts](http-and-contracts.md)).

  Done when: The intended list/detail contract is documented and covered for starts_at and other shared fields; duplicate field definitions are reconciled where practical. Intentional differences remain explicit.

  Completed November 19, 2026: Unified NFL UTC start timestamp presentation and covered model/summary parity; documented the shared contract. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C03 — P3 maintainability.** Reconcile canonical forecast mapper fallbacks. The dedicated mapper is referenced by a repository whose only discovered consumer is a lifecycle test.

  Evidence: HTTP 2 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Both mapping implementations share deliberate missing-value semantics, or the unused repository/mapper is retired after call-site review; lifecycle tests preserve unknown probabilities honestly.

  Completed November 19, 2026: Canonical summary paths now share the mapper and preserve missing probability/confidence as null. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C04 — P2.** Stop attaching canonical predictions under the legacy prediction relationship; avoid loading the legacy row only to replace it.

  Evidence: Models 4, HTTP 3 ([models and ingestion](models-and-ingestion.md), [HTTP and contracts](http-and-contracts.md)).

  Done when: A loaded relationship and its relationship query refer to the declared model type; selected canonical forecasts are passed separately or through a real canonical relation, with publication gates unchanged.

  Completed November 19, 2026: Gated canonical selections now use explicit scoped forecast context rather than replacing legacy model relationships. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C05 — P2.** Reuse the selected canonical forecast through composite page assembly instead of querying it again.

  Evidence: HTTP 4 ([HTTP and contracts](http-and-contracts.md)).

  Done when: NFL and MLB composite pages reuse one selected forecast without repeating its selection query, with unchanged missing-prediction and publication behavior.

  Completed November 19, 2026: Composite readers reuse selected or known-missing forecasts without a second selection query. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C06 — P2.** Batch ingestion player/team identity reads and avoid redundant per-row existence checks; preserve model events.

  Evidence: Models 2 ([models and ingestion](models-and-ingestion.md)).

  Done when: Listed ingestion loops reuse batch identity maps and merge repeated athlete categories; query-growth coverage and event/cast checks prove valid imports remain equivalent.

  Completed November 19, 2026: Implemented batched identity reads and category merging with event-aware saves. Validation: 77 focused ingestion tests (380 assertions), 59 CFB regression tests (207 assertions), affected-file Pint and whitespace checks passed.

- [x] **C07 — P2.** Batch Player lookups for research availability facts.

  Evidence: Models 3 ([models and ingestion](models-and-ingestion.md)).

  Done when: Research fact player identities load once per preview batch and both teams use the same map; prediction outputs and source precedence match.

  Completed November 19, 2026: Preview receives explicit research facts and batches player identities once; repeated-fact query regression and NFL research/availability tests pass. See [implementation validation](implementation-validation.md).

- [x] **C08 — P2.** Load admin group invitations in a bounded batch instead of per-group queries.

  Evidence: HTTP 6 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Invitation query count stays bounded as pool count grows; each group still gets its latest 20 invitations and existing response fields.

  Completed November 19, 2026: Added Group.invitations and per-parent latest-20 eager loading; three-group regression verifies two queries. See [implementation validation](implementation-validation.md).

- [x] **C09 — P2.** Filter/chunk digest recipients and batch already-sent checks.

  Evidence: Services S01 ([services and delivery](services-and-delivery.md)).

  Done when: Recipient memory is bounded and prior-send checks are batched; opt-outs, target times, subscriptions, and concurrent delivery deduplication remain correct.

  Completed November 19, 2026: Digest recipients stream in ID batches with a prior-send anti-join; twelve-recipient regression verifies no per-user history existence reads and correct sends. See [implementation validation](implementation-validation.md).

- [x] **C10 — P2.** Reuse alert templates and candidate recipients across opportunities; retain locking and delivery revalidation.

  Evidence: Services S02 ([services and delivery](services-and-delivery.md)).

  Done when: Recipient/template preparation is reused within the run; locked daily limits, deduplication, and send-time eligibility checks remain intact.

  Completed November 19, 2026: Recipient/template resolution is reused across opportunities, including missing templates; recipient subscription dependencies are eager loaded. Query-growth and delivery-safety tests pass. See [implementation validation](implementation-validation.md).

- [x] **C11 — P2.** Batch CFB live prop history/injuries; skip history when frozen baseline already supplies required values.

  Evidence: Services S03 ([services and delivery](services-and-delivery.md)).

  Done when: History and injury reads are batched for props; complete frozen baselines skip redundant history reads, with equivalent sample, availability, and projection results.

  Completed November 19, 2026: Live props select latest rows in SQL, batch history aggregates and injury existence, and skip history for complete frozen baselines. Live projection/availability regressions pass. See [implementation validation](implementation-validation.md).

- [x] **C12 — P2.** Build one CFB team/game/as-of evidence context and reuse it for QB and prop evaluation.

  Evidence: Models 6, Services S04 ([models and ingestion](models-and-ingestion.md), [services and delivery](services-and-delivery.md)).

  Done when: Eligibility, QB evidence, prop scoring, and variability reuse evidence keyed by game/team/cutoff; bounded-query tests preserve holds and point-in-time selection.

  Completed November 19, 2026: Added explicit batch-scoped cutoff-specific evidence context reused by eligibility/QB/analysis/variability, with scope cleanup. Cutoff and repeated-read regressions pass. See [implementation validation](implementation-validation.md).

- [x] **C13 — P2.** Preload rosters once for CFB player-name matching.

  Evidence: Services S05 ([services and delivery](services-and-delivery.md)).

  Done when: All listed matching paths reuse rosters within a game batch and still reject ambiguous names.

  Completed November 19, 2026: Added scoped game-roster matcher; repeated-name lookup uses one roster read and ambiguous names remain rejected. See [implementation validation](implementation-validation.md).

- [x] **C14 — P2.** Select the latest eligible point-in-time Elo row in SQL.

  Evidence: Models 7, Services S06 ([models and ingestion](models-and-ingestion.md), [services and delivery](services-and-delivery.md)).

  Done when: SQL selects one row with exactly the existing game-date/game-ID order and temporal eligibility; ties and prior-season initialization behavior are covered.

  Completed November 19, 2026: Latest eligible Elo selection orders/limits in SQL while retaining temporal filters and related-game provenance. Existing point-in-time tests pass. See [implementation validation](implementation-validation.md).

- [x] **C15 — P2.** Batch point-in-time quote selection in spread calibration datasets.

  Evidence: Services S07 ([services and delivery](services-and-delivery.md)).

  Done when: Calibration quote selection scales by bounded batches rather than one query per row; selected quote IDs and contract/cutoff rules match.

  Completed November 19, 2026: Spread calibration selects closing quotes in bounded game batches. Two-game query-count regression verifies one read and exact parity with single-game selection. See [implementation validation](implementation-validation.md).

- [x] **C16 — P2.** Aggregate or stream canonical performance statistics instead of hydrating every matching prediction and its latest evaluation; this path already uses withOnly to exclude the wider graph.

  Evidence: Services S08 ([services and delivery](services-and-delivery.md)).

  Done when: Aggregate or streamed evaluation statistics match existing totals and null/error semantics without loading the complete history into memory.

  Completed November 19, 2026: Canonical performance aggregation now streams bounded prediction/evaluation chunks instead of materializing all history. Performance trust tests pass. See [implementation validation](implementation-validation.md).

- [x] **C17 — P2.** Require prepared recommendation season summaries rather than query fallbacks inside Resources.

  Evidence: HTTP 7 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Recommendation list callers supply batch summaries and resource serialization performs no summary fallback queries; missing summary behavior is explicit.

  Completed November 19, 2026: Resources consume prepared NFL season summaries or explicit null; single-prop database fallbacks were removed. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C18 — P2.** Prepare basketball injury impact data in batches and pass results into Resources.

  Evidence: HTTP 8 and architecture debt ([HTTP and contracts](http-and-contracts.md)).

  Done when: Affected basketball injury serializers receive prepared impact data or a guaranteed batch-preloaded scorer; nested player data is loaded and query count stays bounded.

  Completed November 19, 2026: Loaded injury graphs are batch-prepared with explicit impact context before serialization; multi-injury query regression passes. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C19 — P2.** Precompute NFL live win estimates before resource serialization.

  Evidence: HTTP architecture debt ([HTTP and contracts](http-and-contracts.md)).

  Done when: NFL GameResource receives a prepared live estimate without selecting its baseline during serialization; live outputs remain equivalent.

  Completed November 19, 2026: NFL GameResource consumes a prepared live estimate and no longer selects its baseline while serializing. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C20 — P2.** Replace boolean relation cache entries with explicit validity context; clarify live snapshot cache ownership while retaining batched reads.

  Evidence: Models 8, Services S09 ([models and ingestion](models-and-ingestion.md), [services and delivery](services-and-delivery.md)).

  Done when: Validity booleans leave the Eloquent relation namespace; snapshot loading and canonical-ID validation remain batched and serialization remains correct.

  Completed November 19, 2026: Live baseline validity is held in a scoped WeakMap rather than a boolean relation. Serialization and preloaded no-query regression pass. See [implementation validation](implementation-validation.md).

- [x] **C21 — P2.** Pass research preview inputs and matchup presentation context explicitly instead of attaching non-schema attributes.

  Evidence: Models 9, HTTP 5, Services S10 ([models and ingestion](models-and-ingestion.md), [HTTP and contracts](http-and-contracts.md), [services and delivery](services-and-delivery.md)).

  Done when: ResearchPipeline and all three game/page entry points pass explicit research or matchup context; response/preview preparation does not dirty games with those synthetic attributes.

  Completed November 19, 2026: Research and matchup context are explicit inputs, including both NFL prop-preview availability paths. Batch facts clear on success and exceptions; timestamp/team/source checks and preview non-persistence are covered. Validation: 124 overlay/research tests (474 assertions), HTTP boundary and root service tests. See [implementation validation](implementation-validation.md).

- [x] **C22 — P2.** Pass the prediction into betting calculations explicitly instead of swapping a model relationship.

  Evidence: Models 5, Services S16 ([models and ingestion](models-and-ingestion.md), [services and delivery](services-and-delivery.md)).

  Done when: Betting calculators accept the selected prediction explicitly and no longer swap the game relation; pricing, permissions, and numerical outputs match.

  Completed November 19, 2026: Calculators accept the selected prediction explicitly; preparer and recommendation service no longer swap relationships. Resource/delivery regression suites pass. See [implementation validation](implementation-validation.md).

- [x] **C23 — P2.** Define public stat/metric fields and casted values deliberately rather than exposing raw model attributes.

  Evidence: HTTP 11 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Per-sport public field/type contracts are explicit and tested; stat values use deliberate casted access and new internal columns cannot silently change public payloads.

  Completed November 19, 2026: Stat/metric public allowlists prevent incidental schema leakage; stats use casted access and established metric coercion is preserved. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C24 — P3 boundary.** Make dashboard presentation and live snapshot preparation explicit before Resources run. Main paths already batch snapshots; this is conditional query risk.

  Evidence: HTTP 9–10 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Dashboard preparation and prediction live-field inputs have explicit caller contracts; covered resource serialization adds no snapshot/context-selection queries.

  Completed November 19, 2026: API/shared/dashboard live fields and CFB live-board values are prepared before serialization, retaining batch reads. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C25 — P3.** Replace calibration/export/model-suggestion temporary attributes with explicit observation or presentation data. Game/research context belongs to C21.

  Evidence: Models 10,14; Services S11,S15 ([models and ingestion](models-and-ingestion.md), [services and delivery](services-and-delivery.md)).

  Done when: Calibration observations, MLB export horizon, and mapping suggestions travel in explicit non-persisting data structures; values and source provenance match.

  Completed November 19, 2026: Integration retains main's newer readonly NflCalibrationSample with canonical/legacy source selection and result-time cutoffs, preserving decimal rounding and independent stage values; its game reference remains the existing loaded model, while forecasts are non-persisting samples. Redundant cleanup DTOs were retired; MLB export horizon and mapping suggestions travel beside models rather than as synthetic dirty attributes. Validation: relationship/calibration/export suite (106 tests, 1,788 assertions) and root mapping tests. See [implementation validation](implementation-validation.md).

- [x] **C26 — P3.** Rename or remove Team predictions methods returning Builder rather than Relation across six sports.

  Evidence: Models 11 ([models and ingestion](models-and-ingestion.md)).

  Done when: All six Team builder methods are renamed or retired with call sites checked; genuine SportEvent relationships remain unchanged.

  Completed November 19, 2026: Renamed six Team query builders to queryPredictions after checking consumers; genuine SportEvent relationships retained. Six-sport query semantics are tested. See [implementation validation](implementation-validation.md).

- [x] **C27 — P3.** Make NFL prediction convenience-accessor eager-loading requirements explicit.

  Evidence: Models 12 ([models and ingestion](models-and-ingestion.md)).

  Done when: Convenience accessors are removed or supported by an explicit loading contract and focused collection query-growth coverage.

  Completed November 19, 2026: Removed unused NFL Prediction team accessors that could lazy-load; explicit game.homeTeam/game.awayTeam access is covered without queries. See [implementation validation](implementation-validation.md).

- [x] **C28 — P3.** Reuse loaded matchup teams/probable pitchers and clarify recent-game preparation ownership; retain manual batching when it saves queries.

  Evidence: Models 13, HTTP 13 ([models and ingestion](models-and-ingestion.md), [HTTP and contracts](http-and-contracts.md)).

  Done when: Loaded teams/probable pitchers are reused by matchup context; recent-game/pitcher preparation has clear ownership and query counts do not regress.

  Completed November 19, 2026: Query preparation owns recent-game/pitcher hydration; matchup context reuses loaded team and probable pitcher identities. Final agent review repaired a shared prior MLB matchup appearing as distinct instances in both recent-game lists; both instances now receive their team relations through one batched team query, with a regression test. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C29 — P3.** Extract cohesive reporting queries/presentation from large controllers; retain aggregate SQL.

  Evidence: HTTP 14 ([HTTP and contracts](http-and-contracts.md)).

  Done when: The three listed controllers delegate cohesive reporting/query preparation without changing aggregate results or public contracts; unrelated season-filter changes stay separate.

  Completed November 19, 2026: Cohesive reporting preparation moved from the listed controllers into query/presentation services; aggregate SQL retained. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C30 — P3.** Replace runtime schema guessing with explicit supported capabilities where migration guarantees permit it.

  Evidence: HTTP 15 ([HTTP and contracts](http-and-contracts.md)).

  Done when: Known supported schemas/capabilities are explicit, duplicate column probes are removed, and required compatibility/unsupported-sport behavior is covered.

  Completed November 19, 2026: Known per-sport query schema is explicit and tested against migrations; runtime column guessing and duplicate injury probes removed. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C31 — P3.** Consolidate public nested serialization/accessor handling without building another generic abstraction layer.

  Evidence: HTTP 12 ([HTTP and contracts](http-and-contracts.md)).

  Done when: The listed generic attribute guards and repeated nested shapes have deliberate accessor support and clear ownership; field omission and existing numeric/date contracts are preserved.

  Completed November 19, 2026: Known computed accessors and nested public shapes have explicit contracts without a catch-all abstraction. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C32 — P3.** Move cohesive inline validation operations to Form Requests as touched; inline validation is supported Laravel.

  Evidence: HTTP 16 with full location list ([HTTP and contracts](http-and-contracts.md)).

  Done when: Every inline-validation operation listed in the HTTP report is migrated or explicitly retained with a rationale; authorization, validation errors, and rules are preserved.

  Completed November 19, 2026: Moved 34 operations into named Form Requests; retained 11 inline operations with specific gate/ownership/dynamic-input ordering evidence. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C33 — P3.** Consolidate repeated mapping count queries and the confirmed duplicate CFB team reads in one index request.

  Evidence: Services S12–S13 ([services and delivery](services-and-delivery.md)).

  Done when: Each mapping stats helper uses equivalent consolidated counts, and the CFB index request shares one team candidate read for options and suggestions.

  Completed November 19, 2026: Mapping counts use one aggregate each; CFB options/suggestions share a read without synthetic model attributes. Mapping value/query regression passes. See [implementation validation](implementation-validation.md).

- [x] **C34 — P3.** Narrow digest forecast selection before hydration, preserving eligibility order and timezone.

  Evidence: Services S14 ([services and delivery](services-and-delivery.md)).

  Done when: Equivalent status/start eligibility and ordering run before unnecessary hydration; timezone boundaries and the post-eligibility 30-item cap are covered.

  Completed November 19, 2026: Digest SQL narrows eligible status/start and canonical payload loading before the cap; legacy inputs stream in bounded batches with exact kickoff/timezone filtering preserved. Validation: 127 delivery/DI/API tests (832 assertions), 65 CFB pipeline/preparation/export tests (305 assertions), affected-file Pint and whitespace checks passed. See [implementation validation](implementation-validation.md).

- [x] **C35 — P3.** Normalize forecasts once per operation and inject cohesive collaborators at the service and formatter locations listed in the service report; retain justified registry resolution.

  Evidence: Services dependency injection inventory ([services and delivery](services-and-delivery.md)).

  Done when: Every listed forecast-normalization, formatter, and service-locator family is migrated or explicitly retained with rationale; dynamic registries and fresh queued-send checks remain.

  Completed November 19, 2026: Injected cohesive delivery, permission, forecast and signal collaborators; retained documented explicit-model builder adapters and per-send/dynamic factory boundaries. CFB analyzer evidence is injected and batch-scoped rather than expanding to a giant unrelated scoring constructor. Validation: 127 delivery/DI/API tests (832 assertions), 65 CFB pipeline/preparation/export tests (305 assertions), affected-file Pint and whitespace checks passed. See [implementation validation](implementation-validation.md).

- [x] **C36 — P3.** Resolve ESPN sync dependency graphs through container-aware construction; configure concrete collaborators before method injection.

  Evidence: Models 15 ([models and ingestion](models-and-ingestion.md)).

  Done when: All seven FetchGameDetails jobs resolve configured concrete sync collaborators through the container; uniqueness, payload serialization, and behavior remain intact.

  Completed November 19, 2026: Implemented concrete sport sync constructors and container-injected handlers across all seven jobs. Validation: 77 focused ingestion tests (380 assertions), 59 CFB regression tests (207 assertions), affected-file Pint and whitespace checks passed.

- [x] **C37 — P3 optional.** Review remaining EPA estimates, NFL/MLB non-query injury descriptions, and prepared MLB period context. Excludes C18/C19/C24 and pure explanation formatting.

  Evidence: HTTP architecture debt ([HTTP and contracts](http-and-contracts.md)).

  Done when: Remaining EPA estimates, non-query injury descriptions, and period context have a deliberate presentation boundary or documented retention; pure explanation formatting stays allowed.

  Completed November 19, 2026: Retained query-free EPA arithmetic and batch-primed MLB context with explicit rationale and zero-query tests; resolved injury/live calculation debt. Validation: 886 HTTP/integration tests (13,641 assertions), focused boundary tests and affected-file Pint passed; research changes also passed the root 276-test service suite. See [implementation validation](implementation-validation.md).

- [x] **C38 — P2 safeguard.** Enable lazy-loading detection in local/testing environments and assert query growth/serialization boundaries. Strict prevention is enabled for local/testing, with query boundaries and regressions verified.

  Evidence: AppServiceProvider and source search; see review notes below.

  Done when: Lazy-loading detection is active in verified local/test environments, focused query-growth/serialization checks cover affected paths, and justified exceptions are explicit.

  Completed November 19, 2026: Enabled strict lazy-loading prevention in local/testing environments with no blanket suppression. Fixed discovered query boundaries, added growth/serialization tests, and adjusted test-only fixtures to load their asserted relations. Final NFL, API, provider and integration shards pass. The six migration guardrail violations were subsequently repaired and verified; see the validation record. See [implementation validation](implementation-validation.md).

- [x] **C39 — P3 configuration.** Read build/commit version through config rather than direct application env calls. External process environment can still work with config caching; a current failure is not established.

  Evidence: app/Services/Predictions/ModelRunRecorder.php:84–92 ([ModelRunRecorder](/Users/bey/Herd/github/picksports/app/Services/Predictions/ModelRunRecorder.php:84)).

  Done when: Build version is read through config with existing precedence/fallback preserved; behavior is verified with cached configuration and externally supplied environment variables.

  Completed November 19, 2026: Build version is evaluated through configuration with original environment precedence/whitespace rules and existing Git fallback. Configuration-based provenance regression passes. See [implementation validation](implementation-validation.md).

- [x] **C40 — P3 measurement first.** Measure repeated per-game reads in the CFB scoring export; batch within bounded game chunks only where the cost and complexity justify it.

  Evidence: app/Console/Commands/CFB/ExportScoringTrainingCommand.php:75–167 ([CFB scoring export](/Users/bey/Herd/github/picksports/app/Console/Commands/CFB/ExportScoringTrainingCommand.php:75)).

  Done when: Export query/memory cost is measured; batching is implemented if warranted, or retention is documented with evidence. Accepted-source, kickoff, and manifest semantics match.

  Completed November 19, 2026: Ten-game local fixture measured source/affiliation reads reduced from 30 to 2 through 50-game chunk preparation. Peak process memory remained 87.5 MiB. Per-game large payload selection was intentionally retained with temporal/resume/manifest coverage. Validation: 127 delivery/DI/API tests (832 assertions), 65 CFB pipeline/preparation/export tests (305 assertions), affected-file Pint and whitespace checks passed. See [implementation validation](implementation-validation.md).

## Relationship mapping checklist

Complete the mapping and schema/data checks before dependent relationship or constraint changes. The [relationship map](relationship-map.md), [model inventory](relationship-inventory.md), and [ownership review](relationship-ownership-review.md) record the local review and its explicit production boundary.


- [x] **C41 — P2 relationship inventory.** Map persisted associations across every application domain, including sports, identity, calculations, betting, delivery, users/groups, subscriptions and developer-platform records.

  Evidence: [relationship map and coverage requirements](relationship-map.md).

  Done when: Every model/domain has a reviewed association inventory with tables, keys, cardinality, nullable behavior, useful inverses, constraints, deletion policy, provenance, producer/consumer ownership and loading requirements. Intentional query/resolver associations are distinguished from missing relationships.

  Completed November 19, 2026: Inventoried all 193 application models, 538 associations, seven package targets and domain ownership/loading/history policies. Generated source/schema/integrity reports record keys, cardinality, nullable behavior, indexes, inverse candidates and deletion policy. Environment: newly migrated disposable SQLite, not production. See [implementation validation](implementation-validation.md).

- [x] **C42 — P2 Eloquent relationship correctness.** Reconcile model declarations with the map: foreign/owner keys, return types, cardinality, useful inverses and latest/current selection semantics.

  Evidence: [declared associations](relationship-map.md); overlaps C04, C08, C20, C26 and C28 at identified locations.

  Done when: Each mapped declaration is correct or explicitly retained; supported relation queries and eager loading return the intended records, with coverage for custom keys, missing links and sport boundaries. Correct existing relationships are retained and unused inverse methods are not required merely for symmetry.

  Completed November 19, 2026: Validated mapped keys, added four useful ownership relations, corrected OAuth client owner keys/type, and tested eager/lazy/direct relation behavior. Existing correct selectors and justified query/resolver associations are retained. See [implementation validation](implementation-validation.md).

- [x] **C43 — P2 referential integrity.** Verify foreign keys, uniqueness/indexes, nullability and deletion behavior against migration history, an identified database schema and existing data before proposing constraints.

  Evidence: [schema and data verification requirements](relationship-map.md).

  Done when: Reviewed schema environment and read-only duplicate/orphan/mismatch checks are recorded; needed fixes have safe migrations and local/disposable validation, or retention is justified. Historical evidence is preserved and no unreviewed data deletion/backfill is used to satisfy constraints.

  Completed November 19, 2026: Audited 199 local model/package tables and tested seeded invalid-reference/duplicate cases. Added an additive one-join-link-per-group unique index with duplicate preflight that deletes nothing; tested migration enforcement and failure preservation. Production/MySQL inspection remains a release-time check, not claimed here. See [implementation validation](implementation-validation.md).

- [x] **C44 — P2 identity resolution.** Map provider IDs, public ULIDs, polymorphic user bets and table/ID-based game or prediction references to explicit supported resolution rules.

  Evidence: SportEventProviderMapping, UserBet, MarketQuote, GameOddsSnapshot, BetDecision, PredictionEvaluation and LivePredictionSnapshot in the [relationship map](relationship-map.md).

  Done when: Every dynamic reference family has a documented type/key contract and tested resolver or real relation, including sport validation, legacy compatibility and missing/ambiguous identities. No guessed model class or naked ID resolves across unrelated sport tables; a morph-map conversion is not required if a resolver is appropriate.

  Completed November 19, 2026: Implemented allowlisted sport/table/provider/public-ID/typed-source resolution, protected legacy/canonical morph references, rejected cross-sport event synchronization before writes, and omitted invalid presentation references. Missing allowlisted legacy ledger IDs remain representable. Wrong/missing/ambiguous identity regressions pass. See [implementation validation](implementation-validation.md).

- [x] **C45 — P2 historical relationship semantics.** Separate current roster/injury/pitcher associations from game-time stats, snapshots, forecast revisions and immutable evidence.

  Evidence: [historical ownership and cutoff requirements](relationship-map.md).

  Done when: Historical readers use the recorded team, snapshot, forecast revision and relevant cutoff; transfers, later corrections and current-state changes cannot silently rewrite the association represented by stored evidence. Existing point-in-time safety and source lineage tests remain valid.

  Completed November 19, 2026: Recorded historical team/stat/snapshot/revision identity remains authoritative, with transfer, custom ESPN owner-key and immutable-observation regressions. Retained the existing basketball boxscore writer policy that can update mutable Player.team_id during historical imports; this ID does not redesign current-roster writers. See [implementation validation](implementation-validation.md).

## Audit baseline qualifications

These qualifications describe the original audit, before the checked implementation work above. They are retained to explain scope and do not override completion notes.

- C01 is confirmed on the normal shared caller/job path as well as the replacement actions. CFB overrides have separate transaction/completeness safeguards and are excluded from this atomicity finding.
- C02 is a contract decision, not a demonstrated frontend failure. The frontend declares starts_at optional and the NFL detail consumer uses it; intentional list/detail differences need not be erased.
- C03 is lower priority: the dedicated mapper is used by CanonicalPredictionReadRepository, and the only discovered repository consumer is the WNBA lifecycle test. Different fallbacks are verified, but divergent live responses from that mapper are not established.
- C16 already restricts eager loading with withOnly(['latestEvaluation']). Its issue is unbounded prediction/evaluation hydration for aggregates, not loading the entire default graph.
- C17, C18, and C24 distinguish possible serializer fallbacks from routes already supplying batch-prepared data. Keep existing live, injury, and MLB period-context batching.
- C23 distinguishes raw stats, which bypass casts, from team metrics, which use getAttribute and then numeric coercion. Both currently derive their field set from schema attributes.
- C33 duplicates the CFB team read in a single index request: mappings() loads suggestion candidates and espnTeams() separately loads options. This is confirmed in CfbdTeamMappingController::index.
- C37 excludes pure stored-summary explanation formatting and does not duplicate basketball injury query cleanup, NFL live calculation, or live snapshot preparation.
- C38 is based on a source search under app, bootstrap, and tests finding no preventLazyLoading, shouldBeStrict, or preventAccessingMissingAttributes configuration. Enable safeguards with focused coverage, not a blind production switch.
- C39 follows the project's config convention. Laravel config caching does not make externally supplied process variables universally unavailable; dotenv-only lookup is the relevant risk.
- C40 is measurement-led background export work, not an established hot-path incident.

## Detailed evidence

- [Relationship map](relationship-map.md), [model inventory](relationship-inventory.md), and [ownership review](relationship-ownership-review.md): completed local C41–C45 evidence.
- [Implementation validation](implementation-validation.md): final test results, retained decisions and the resolved migration guardrail review.

- [Models and ingestion](models-and-ingestion.md): 15 finding groups and sport subclass locations.
- [HTTP and contracts](http-and-contracts.md): 16 finding groups, inline-validation locations, and Resource architecture-test exceptions.
- [Services and delivery](services-and-delivery.md): 16 finding groups, service/formatter call sites, and justified exceptions.

These reports overlap by design; the stable C identifiers above are the sole completion list. Source line references describe the reviewed working tree and must be checked before implementation.

## Implementation validation

Use focused rollback/completeness tests for C01, contract/value parity for serialization and mappings, and query-growth checks where loops or serializer fallbacks are changed. Verify the local test environment before running application tests. Preserve database event behavior before replacing model writes with insert/upsert. Keep network requests outside long transactions.

For each completed ID, record the affected-file formatting and relevant tests. Documentation-only tracker updates need link/reference and whitespace checks; they do not establish that an implementation has passed tests. The original checklist documentation pass required no application execution. The subsequent independent final review used isolated SQLite tests, local fixtures and provider fakes as recorded in [final review](final-review.md); it did not access production or refresh providers.
