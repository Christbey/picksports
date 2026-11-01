# Codebase Reference

This document is a working reference for the PickSports codebase so future changes can be grounded in the actual models, controllers, actions, and known data semantics instead of inferred assumptions.

It is intentionally practical rather than exhaustive.

## Purpose

Use this document before changing:
- sport model semantics
- game page data contracts
- prediction inputs/outputs
- team metrics queries
- season / season type logic
- conference / division / league / alignment logic

This should be treated as the first-stop context document for sports-domain work.

## High-Level Architecture

The app is organized by sport with a shared abstraction layer.

Core backend layers:
- `app/Models/{Sport}`: Eloquent models per sport
- `app/Actions/{Sport}`: prediction, Elo, team metrics, trends, grading
- `app/Actions/ESPN/{Sport}`: ESPN ingest/sync actions
- `app/Actions/OddsApi/{Sport}`: odds and player-prop ingest
- `app/Http/Controllers/Api/V2`: shared sport API controllers
- `app/Services/Api/V2`: sport context and query services
- `app/Http/Resources/{Sport}`: API resource serializers
- `app/Console/Commands/{Sport}`: operational commands
- `resources/js/composables`: shared and sport-specific game-page/prediction-page data hooks
- `resources/js/components/game-page`: shared game-page UI

Shared route registration lives in [routes/api-v2.php](/Users/bey/Herd/github/picksports/routes/api-v2.php).

## Shared API Patterns

`SportContextResolver` selects sport models and capabilities. V2 controllers
validate requests and use shared query services such as `SportGameQuery`,
`SportPredictionQuery`, `SportTeamTrendQuery`, and `SportTeamMetricQuery`.
Resources serialize the prepared data. The old abstract API controller layer
was removed with V1 on 2026-09-25.

## Prediction lifecycle ownership

The prediction pipeline audit on 2026-10-28 distinguishes three record types:

- Sport prediction rows (`{sport}_predictions`) are mutable projections used by legacy readers, live updates, narratives, betting workflows, and historical analysis.
- Imported canonical rows are created by `CanonicalPredictionSyncService` and retain `detail_source`, `detail_sport`, and `detail_id`. Importing an old row does not create a verified immutable calculation run or make it eligible for canonical public reads.
- Native canonical forecasts are created by `PredictionLifecycleOrchestrator` from a release and a frozen input snapshot. They have immutable revisions, explicit publication state, and versioned evaluations.

`prediction_lifecycle.canonical_pipeline.{sport}` controls scheduled canonical work;
`prediction_lifecycle.canonical_reads.{sport}` selects readers. These switches are
independent. Enabling either switch does not migrate all consumers or authorize
removing the sport prediction tables.

| Consumer or producer | Ownership and remaining dependency |
| --- | --- |
| Prediction API lists, details, available seasons/dates, and per-game forecasts | `SportPredictionReader` selects the existing legacy or canonical query and prepares the matching resource. Canonical misses never fall back to legacy rows. Legacy integer IDs and canonical public IDs remain distinct. |
| MLB and NFL composite game pages | Use the same reader and return a null prediction when their selected source has none. MLB previously bypassed canonical source selection. Loaded games are reused. |
| Dashboard, live scoreboard, featured forecasts, performance, admin card exports | Retain explicit legacy/canonical branches through `CanonicalSportPredictionQuery`; they have distinct summary, presentation, filtering, and historical aggregation needs. `DashboardSportConfig` shares dashboard/scoreboard display settings and permission filtering, with model classes resolved by `SportContextResolver`. Card-export compatibility lookups use that same model registry. |
| Native canonical generation | All seven typed sport actions delegate linked-event validation and draft/publication dispatch to `PredictionLifecycleOrchestrator::generateForGame`. Sport calculators and snapshot builders remain distinct. CFB retains published bet-decision recording. |
| Legacy generation and snapshots | `AbstractPredictionGenerator`, `NflPredictionPublisher`, and `PredictionFeatureSnapshotRecorder` still produce mutable forecasts and training/research inputs. Their calculations are not interchangeable with the native canonical calculators. |
| Evaluation | `AbstractGradePredictions` and `PredictionEvaluationRecorder` grade mutable sport rows. `CanonicalTeamGameEvaluator`, `SportEventResultRecorder`, and `CanonicalPredictionEvaluator` append immutable result/evaluation revisions. Finalization now discovers canonical-only work, delayed final scores, score corrections, and missing/current-scoring-version evaluations independently of public read switches. The queued listener reloads current scores and retains legacy/prop grading. Repeated identical results are idempotent; a correction back to an earlier score appends a new result and evaluation revision. Scoring conventions and persistence guarantees differ. |
| Evaluation recovery command | Migrated: `sports:rebuild-prediction-evaluations` selects canonical recovery per sport from `canonical_pipeline` ownership and delegates to `CanonicalTeamGameEvaluator`. Public read flags do not select grading ownership. The shared sport context registry replaces a duplicate prediction-model map; the existing default sport order remains unchanged. | Explicit `--forecast-source=legacy` retains historical mutable evaluation rebuilds; `canonical` supports deliberate native recovery. Canonical scans are bounded, use final scored games and retain evaluator age/clock/publication guards, result correction revisions and idempotency. Missing native forecasts are skipped without fallback. Season scope and the global successful-row limit span mixed ownership. No approval, generation, schema, rollout or schedule change. |

| Notifications and shared betting workflows | `PredictionDeliverySource` supplies source-neutral forecasts to `AlertService` and `DailyDigestService`, using the same per-sport read switch and verified canonical query as the API. `BettingValueAlertFormatter` normalizes both queued legacy models and native canonical models. `GameBettingRecommendationService` reads public, non-tracking, pregame-safe approved decisions tied to the exact selected canonical revision; it never derives a new approval or falls back to legacy decisions. Canonical forecasts without an approved public decision can remain digest watchlist leans. Stored spread/total lines retain their sign conventions and point-edge units; moneyline edges remain percentage points. Quote observation timestamps are used for the 36-hour freshness check when available. Queued canonical alerts recheck their exact decision and current forecast before delivery. The dashboard uses the shared recommendation service instead of a duplicate calculator map. |
| Sport-specific signal panels and MLB pick generation | NBA/NFL/MLB signal services and all MLB game-pick builders consume `BettingForecast` through `BettingForecastSource`. Legacy models are normalized only at that source boundary. Canonical mode reads verified published forecasts and frozen input context, with no legacy fallback. Descriptive signal scoring cannot create canonical approval: public signal bets come from exact-revision approved public decisions. Native MLB candidate generation retains its existing formulas but remains tracking-only pending validation of that source. Missing frozen pitcher context is not filled from mutable ratings. Native pregame forecasts do not supply legacy live projections; MLB reports that live coverage as unavailable. Historical forecast reads are batched, preserving NFL's bounded history query count. |
| MLB candidate identity | `mlb_pick_candidates.canonical_prediction_id` references native `predictions` separately from the historical `prediction_id` foreign key to `mlb_predictions`. Candidate resources expose both fields and source metadata. Decision records use the appropriate prediction table and ID; historical rows and legacy references are retained. Deploy the additive migration before running updated candidate generation. |
| Recommendation and narrative publishers | MLB recommendation envelopes and market-aware tracking projections consume `BettingForecast`; canonical envelopes use exact-revision approved public decisions and retain stored prices. Shared MLB presentation sanitization serves both resource sources. Narrative rendering supports verified native forecasts without legacy reads; canonical prose is deterministic, uses stored public approvals for betting language, and is cached separately by revision and current content hash rather than mutating immutable predictions. Approval withdrawal, expiry, and forecast withdrawal are rechecked before cache use. The NBA narrative sync command selects the configured source; all new NBA generation uses the shared job. The old NBA job is only an adapter for existing queued IDs. Legacy prose, AI generation, metadata persistence, and historical record identities remain supported. Default V2 narrative exclusions remain unchanged. |
| Remaining specialized writers and live consumers | Legacy-mode pregame calculators, legacy generation/decision recorders, and historical analysis still retain legacy identities. Live writers now persist separate snapshots using source-selected pregame baselines. `GameFinalizationDispatcher` discovers pending canonical evaluations when the sport canonical pipeline is enabled, alongside outstanding legacy grading and prop work. These writers are not retired by migrating recommendation/narrative readers. |
| Historical identity and backfills | `CanonicalPredictionSyncService`, `CanonicalPredictionLineageResolver`, training exports, historical reconstruction, and legacy backtests retain the legacy record identity and snapshot linkage. User bet references also preserve legacy sport/model IDs. |
| Scheduling and operations | `routes/console.php` schedules legacy and gated canonical work. The NFL pregame command now runs canonical generation/readiness and bounded research without legacy generation; completeness checks use fresh verified canonical input evidence and permit idempotent reuse. `SportsPipelineRegistry` still exposes legacy generation/grading stages. |

Safe retirement must proceed per consumer and sport: migrate live/narrative,
notification/betting, operational, and historical consumers; preserve historical
bet and snapshot references; verify public field permissions and identifier
contracts; then verify canonical publication coverage and evaluations through
`CanonicalSportCutoverReadinessService` and the NFL-specific readiness checks.
Readiness reports measure forecast coverage and safety, not whether every legacy
consumer has been migrated. Remove legacy scheduling and writers only after those
dependencies have been replaced. This audit does not establish production data
coverage or change production feature switches.

## NFL canonical recommendation evidence

Release `1.2.0` uses `nfl-pregame-v3`. Native input snapshots freeze `recommendation_context` version `nfl-recommendation-context-v1`: deterministic recommendation outputs, true-EPA/analysis metadata, candidate gate configuration and model identity. The existing historical-Elo calculator runs through its non-persisting current-pregame path, after the stored-data true-EPA preflight. Native primary forecast arithmetic remains unchanged. Recommendation evidence is explicitly separate from the native primary forecast; true EPA is not newly applied to that forecast or labeled calibrated.

`NflCandidateDecisionPolicy` owns the existing candidate, selection, exact quote, pairing, and provider-observation freshness rules for both historical and native recorders. `NflCanonicalDecisionRecorder` writes one released tracking candidate, pass, or hold per market, with the native public ID, calculation-run UUID, input-snapshot ID and content hash. Native decisions never populate the legacy feature-snapshot foreign key, are never public, and never represent executed wagers. Withdrawn or superseded revisions cannot record new observations. Readiness requires the current verified revision and matching run/snapshot lineage; legacy ID collisions, older native decisions, and missing context cannot satisfy it.

The ordered NFL command and registry no longer invoke legacy generation. Bounded research uses frozen canonical recommendation evidence as its stored baseline when the canonical pipeline is enabled, retaining its missing-baseline hold on a native miss. Pipeline-order monitoring follows canonical generation/pregame heartbeats instead of legacy writer heartbeats. Current canonical evidence must satisfy the existing freshness window; unchanged inputs can reuse an immutable revision rather than fabricating a refresh. Readiness retains missing/quarantined EPA holds, required paired market coverage, all-three-market dispositions, candidate release coverage, and configuration blockers. It projects only market/recommendation data from native inputs, avoiding legacy snapshot reads and unrelated diagnostics. Existing historical calibration, reconstruction, training/export, live, and explicit legacy-generation paths retain their identities.

Deployment must register the new release through the existing release-replacement workflow with an explicit effective timestamp. Existing published forecasts, historical records, approvals, feature switches, and provider refresh schedules are not rewritten by this code change.

## Active pregame writer retirement

`PredictionDeliverySource::legacyWriterRetired` requires both `canonical_pipeline.{sport}` and `canonical_reads.{sport}`. With both enabled, mutable pregame `execute` calls fail before writing forecasts or feature snapshots; existing generation commands and registry prediction steps use native generation instead. Morning legacy generation schedules are skipped, while canonical schedules retain their existing times. The MLB post-odds command remains an alias to native generation so its cadence and heartbeat survive. Neither switch is changed by this implementation. Pipeline-only deployments retain legacy generation for readers that still select it.

Remaining active dependencies have source-aware paths: MLB probable-pitcher changes regenerate native forecasts; native MLB generation materializes F3/F5 features from verified frozen pitcher inputs, with source/revision-separated period feature lineage as described below; tabular/period shadow inference uses `CanonicalShadowInputs`. Shadow outputs have an explicit native prediction foreign key, never a fabricated legacy feature-snapshot ID. Native tracking decisions retain run/input lineage and the `canonical_shadow_inputs_unvalidated` hold, because promotion of a legacy artifact does not validate a different input contract. Public approval rules are unchanged. Previously queued legacy NFL shadow jobs stop after retirement; historical inference remains available against its original corpus. Native publication invalidates prediction/dashboard caches after commit and queues source-correct narrative jobs for the six sports whose old shared writer dispatched them, retaining coverage-repair suppression. Deploy `2026_10_28_195953_add_canonical_prediction_to_shadow_model_outputs` before this code; rollback refuses to discard populated native lineage.

CBB tournament outlook reads selected probabilities in one batch; hypothetical matchups retain non-persisting previews. Basketball coverage repair uses verified native coverage after read cutover. Completeness, pipeline-order, heartbeat, and MLB monitoring checks accept native evidence rather than requiring fresh legacy rows. NFL simulations use selected native forecasts and same-release, point-in-time canonical residual history. Missing history retains the existing minimum-sample hold; provisional previews cannot be captured, and an absent native pre-market basis is reported unavailable. Captured simulation records retain the native source namespace and exact run/input lineage.

Historical reconstruction, calibration/training exports, old bet/decision grading, explicitly versioned simulation/shadow observations, and non-persisting previews keep their historical contracts. Those retained records do not require active mutable pregame generation. Legacy calculators and compatibility mode cannot be deleted while these supported historical paths and deployments with legacy reads remain.

## AI analysis and research compatibility retirement

Daily AI analysis selects the same source as `PredictionDeliverySource` across all seven sports. Native payloads use canonical primary markets, verified frozen market inputs and run/input lineage. NFL recommendation evidence stays separate from primary forecasts. `SportsAiPredictionAnalysis.canonical_prediction_id` prevents collisions with historical sport prediction IDs; legacy API enrichment excludes native rows. The publishing policy restricts native betting language to stored exact-revision public decisions and retains AI/guardrail downgrades. It never turns tracking observations into approvals. Source selection and the payload/approval hash are checked again after AI review.

NFL web research shares `ResearchPipeline::storedForecast`, using frozen native recommendation evidence when either the pipeline or read switch is enabled. Native misses retain the missing-baseline hold instead of reading a legacy row. Explicit non-persisting research candidates retain their complete input contract. The unused `NotificationTemplatePreviewService` has been removed; notification delivery and template generation use their existing active services.

Deploy `2026_10_29_121751_add_canonical_prediction_to_sports_ai_prediction_analyses` before this stage. Populated canonical analysis lineage blocks destructive rollback. Historical analyses, legacy compatibility readers, grading/calibration/backtests, queued narrative wrappers, and NBA legacy narrative context still have supported callers and remain available.

## MLB bet-filter observation retirement

`mlb:snapshot-bet-filter` selects forecasts through `BettingForecastSource`, replacing its direct legacy prediction query. Native observations require verified frozen pregame inputs, a future kickoff, and the exact selected revision at persistence. `mlb_bet_filter_results.canonical_prediction_id` provides an explicit native foreign key and daily uniqueness namespace; legacy observations retain their original references. The migration refuses to roll back populated native lineage.

Native candidates keep the existing `canonical_approval_required` pass classification and tracking-only metadata. Totals consume the frozen canonical market total; legacy totals retain their metadata contract. Grading can settle a recorded native observation after its forecast is withdrawn or superseded, using the recorded home-margin/total line rather than a mutable legacy baseline. Historical legacy grading retains its original calculation, and source-separated reports avoid pooling historical and native observations. No observation creates a public approval or executed wager. The command reuses the shared American-odds conversion for CLV.

Historical MLB recommendation-readiness/calibration reports, CFB adaptive-calibration corpora, and NBA legacy narrative context still have supported callers; their historical input contracts are retained rather than relabeled as native evidence. NFL team evidence now follows the shared trend forecast boundary described below.

## Dashboard diagnostics and model mapping retirement

`DashboardPayloadInspector` follows the per-sport canonical read switch. Canonical diagnostics aggregate only the verified published forecasts returned by `CanonicalSportPredictionQuery`, preserving the inspector's stored game-date scope. Counts do not hydrate prediction JSON, and freshness uses the selected forecasts' generation timestamp. Diagnostics identify their `source`; missing native coverage reports a gap, while unavailable native storage reports unavailability. Neither falls back to legacy counts. Native route availability also remains independent of a legacy prediction model registration. Legacy-mode diagnostics retain their existing counts and timestamps.

Dashboard and live-scoreboard sport settings now live in `DashboardSportConfig`. Existing sport order, names, colors, permission checks, MLB delayed/inning behavior, day windows, recommendation holds and resource contracts remain intact. Their distinct source queries and serializers are retained. The shared model registry replaces the separate hardcoded dashboard, scoreboard and admin card-export prediction-model mappings. No feature switch, forecast calculation, approval rule or database schema changes in this stage.

## Game delivery legacy-read retirement

`SportGameQuery` selects prediction eager loading through the same per-sport canonical read boundary used to attach forecasts. Canonical game lists, team-game lists, detail responses, and MLB/NFL composite pages no longer fetch mutable sport predictions before replacing them with verified native forecasts. Summary, detail and page relation profiles share one legacy-mode relation declaration. Native gaps remain gaps, including draft, withdrawn and unsafe forecasts; the read switch still restores legacy-mode behavior. Existing resource shapes, season/phase/date filters, permission gates, pitcher context and NFL injury loading remain intact. This stage changes no schema, generation, approval rule or production switch.

## MLB monitoring input retirement

`MlbModelMonitoringService` uses the shared `RecordedShadowInputSource` to resolve observation safety/hash fields and weekly baseline fallbacks from the recorded source identity. Native observations reuse `CanonicalShadowInputs` validation and primary-market margin conventions; superseded observations retain their original native lineage, while withdrawn, unsafe, or mismatched-game inputs do not fall back to legacy snapshots. Recorded multi-market explanation outputs and scalar observations retain their existing precedence. Full-game primary markets never fill missing F3/F5 period baselines. Historical observations continue using their original feature snapshots, independently of the public read switch.

Monitoring batches native timing/lineage and primary-market reads without hydrating frozen input JSON. It loads legacy snapshot outputs only for historical observations, skips unused metadata payloads, and no longer loads feature snapshots for decision summaries. Existing week/game deduplication, metric formulas, admin access, response fields, and tracking/approval rules remain unchanged. No schema or production-switch change is required.

## Recorded shadow decision retirement

The scheduled `sports:record-shadow-bet-decisions` command and MLB monitoring share `RecordedShadowInputSource`. Source selection follows each recorded observation's explicit native foreign key, independently of public read switches. Native sport, game, prediction namespace and ID must match; ineligible native inputs never borrow attached legacy snapshots or promotion metadata. Decision recording loads the frozen market/input context it requires, while monitoring retains its compact projections. Historical observations keep their original full feature-snapshot contract.

The recorder processes ascending-ID batches of 100 with a fixed per-run upper ID while preserving `--artifact`, `--limit`, immutable decision hashes, exact quote/reference-line selection, uncertainty/promotion checks, period semantics and the `canonical_shadow_inputs_unvalidated` hold. Model persistence still rechecks promotion and NFL uncertainty before accepting a shadow bet, without native-to-legacy fallback. Non-NFL no-bet saves skip unused snapshot reads; NFL explanation enrichment remains intact. Schedule timing, heartbeat names, generation, approvals and production switches are unchanged.

## NFL evidence reconciliation retirement

`NflEvidenceLedgerAudit` selects frozen canonical recommendation evidence when either NFL's pipeline or read switch is enabled, matching the research baseline source boundary. It checks the recommendation contract and exact model version, verified native run/input timing, publication before the frozen kickoff, and current kickoff identity. Native misses do not read legacy forecast snapshots. Canonical primary projections do not fill missing recommendation outputs; frozen consensus lines and per-market provider observation timestamps supply market diagnostics.

Canonical reconciliation and capture share `NflEvidenceForecastSource`, projecting frozen inputs in batches of 25. Native captures use policy `nfl-evidence-canonical-v1`, preserving fresh-source, configuration, kickoff and early/late holds. Each game/source has at most one early and one late capture. The selected revision and runtime configuration are checked again before persistence. Missing native evidence does not fall back to historical snapshots.

Migration `2026_10_29_193510_add_canonical_lineage_to_nfl_evidence` adds explicit native foreign keys to captures and observations. Native observations leave the legacy `prediction_id` null. Reconciliation reports the actual derived capture ID only when its exact native run/input lineage matches; historical IDs and grades cannot satisfy native coverage. Grading and reports separate sources, including latest-observation ranking. Frozen captures remain gradeable after the originating forecast is withdrawn; this grants no approval. Unsupported conditions are excluded from expected grade counts.

Apply the lineage migration before running the new code. Future recommendation inputs freeze `evidence_source_version`, the full runtime configuration hash, and compact QB identity metadata. Existing inputs without that source contract remain ineligible for new capture; do not backfill their hashes. Primary forecast arithmetic is unchanged. Both canonical switches off retain historical capture compatibility. Explicit `--forecast-source=legacy` reports remain available without switching flags, and monitoring cache keys include the source.

Preregistered historical evidence trials remain restricted to the old capture policy. Native captures are tracking-only and cannot produce trial shadow outputs, bets or public approvals. Native trial registration and final removal of historical compatibility remain separate retirement work. Scheduler cadence, provider access and heartbeat behavior are unchanged. Rollback refuses to discard populated native lineage.

## NFL active spread-assessment retirement

`NflSpreadAssessmentService`, called by the deterministic recommendation preview, selects canonical residual history when either NFL canonical switch is enabled. `NflSpreadValidationDataset` reuses the shared projected `NflEvidenceForecastSource` rather than duplicating native eligibility parsing or reading legacy snapshots. Canonical rows retain native run/input lineage and a null historical snapshot ID, use frozen recommendation margins and consensus home lines, and never substitute primary canonical markets, mutable odds, or historical rows for missing evidence. Cache keys include the source.

Completed regular-season, exact-model, pre-kickoff generation, frozen/current kickoff identity, target exclusion and result-availability checks remain in force. The 200-game minimum and held-out validation requirement remain; all estimates stay uncalibrated and ineligible for betting. Missing native history can therefore reduce the sample and hold probability output. Provider quote timestamps are retained when frozen, with missing timestamps left unavailable; unavailable benchmark candidates remain null.

Historical spread validation and frozen replay commands still default explicitly to the historical corpus, preserving their prior line conventions and benchmarks regardless of canonical switches. This stage changes no forecast formula, schedule, schema, release approval or production setting. Adaptive-calibration history now follows the separate source-aware contract below.

## NFL adaptive-calibration history retirement

`previewPregame` selects canonical adaptive history when either NFL canonical switch is enabled. Historical publication and the general historical preview retain their legacy corpus. `NflCalibrationHistory` shares the existing frozen recommendation reader and sample validation rather than duplicating native eligibility parsing. Samples remain unsaved calculator adapters with no legacy model identity; native samples retain explicit run/input lineage.

Native history requires completed regular-season games, exact recommendation model version, verified pregame timing, matching frozen/current kickoff, and a result observed strictly after that kickoff and before both evaluation time and target kickoff. The prior-date rule, bounded lookback and eight-entry cache bound remain. History and regime-dampener caches separate sources and native target kickoffs. Missing native samples never borrow legacy rows or primary canonical markets.

The canonical input builder saves the calculator’s existing pre-adjustment point and win-probability inputs under `recommendation_context.outputs.calibration_inputs`; existing inputs are never backfilled. Point and win-probability adjustments consume their respective frozen stages; absent stages are held rather than filled with published outputs. The regime dampener consumes published recommendation evidence. Existing sample thresholds, coefficients, caps, spread-bias opt-in and approval rules remain unchanged. A smaller native corpus can leave adjustments unapplied. Historical snapshots are retained for explicit compatibility; this stage changes no schema, schedule, production switch or release approval.

## MLB calculation diagnostic retirement

`mlb:explain-prediction` and `mlb:audit-prediction-calculations` select canonical forecasts when either MLB canonical switch is enabled. Both accept `--forecast-source=legacy` for historical inspection without changing production flags. Missing or ineligible native forecasts do not fall back to mutable predictions. The existing `--historical` explanation option remains a phase label; it does not select a different source or regenerate forecasts.

Canonical explanations use frozen team, pitcher, weather and market inputs, stored run diagnostics, native markets and exact run/input lineage. Missing legacy adjustment fields remain unavailable; current model coefficients and game odds do not reconstruct them. Native spreads use the existing home-minus-away convention. Confidence uses only a stored score and remains unavailable when absent; diagnostics grant no calibration or bet approval. Frozen/current kickoff identity, generation/publication timing and input availability are checked independently of historical feature snapshots.

The audit shares invariant checks with historical explanations and retains its filters, canceled/postponed and incomplete-final exclusions, sampled failures and JSON/text behavior. It now streams descending-ID batches of 25 rather than loading the full corpus. Native probability pairs and derived scores are checked against their actual stored outputs. Historical calculations, explicit compatibility and referenced storage remain intact; no generation, schema, schedule or production setting changes are included.

## MLB period-feature lineage retirement

`MlbPeriodForecastSource` supplies F3/F5 pitcher inputs through shared verified canonical input/run checks after MLB read cutover, replacing the weaker duplicate lookup. Frozen/current kickoff identity, stored game time, publication and source availability must agree. Missing or ineligible native inputs leave pitcher context unknown; independent historical period features retain their existing formulas. Explicit historical exports continue using their original snapshot corpus.

Migration `2026_10_30_175657_add_forecast_lineage_to_mlb_period_feature_snapshots` adds a default historical source marker and a nullable native prediction foreign key. Existing rows retain their hashes and legacy identity. Native feature hashes include the source and exact native revision, including a distinct canonical unknown-source case; historical feature hashes are unchanged. Persisted numeric model inputs and feature-version names are unchanged. Native records are immutable and block destructive rollback, including records with unknown pitcher provenance.

The store separates sources on reads and matches native records to the current selected safe forecast, so a cutover, withdrawal or revision change cannot reuse legacy or stale native pitcher values. Materialization checks source identity again under event/forecast locks before writing. Period shadow inference pins feature reads to its own native revision; missing matching features retain the existing no-safe-snapshot hold. The inference schema, period math, model-promotion checks and `canonical_shadow_inputs_unvalidated` betting hold remain unchanged.

Apply the lineage migration before code. Existing historical rows are never relabeled as native evidence. Normal materialization populates the new source cohort; no forecast generation, provider refresh, cadence or production-flag changes are introduced by this stage.

## Remaining writer retirement dependencies

The following code paths still write or require legacy prediction identities. This is a code dependency audit; it does not establish production coverage or authorize disabling a writer.

| Writer or operational entry point | Remaining dependency | Replacement required before retirement |
| --- | --- | --- |
| `AbstractPredictionGenerator` and sport `generate-predictions` commands | Mutable sport rows, `PredictionFeatureSnapshotRecorder`, model-run provenance, and narrative jobs. Compatibility-mode generation and explicit historical reconstruction record legacy snapshots. Active generation is guarded after both canonical switches are enabled. | Preserve every active snapshot consumer using native calculation runs/frozen inputs, or explicitly retain the historical path. Verify sport-specific model behavior rather than assuming native and legacy calculators are numerically interchangeable. |
| `NflPredictionPublisher` / `GeneratePredictionFromHistoricalElo` | Explicit legacy/historical commands retain atomic prediction + feature-snapshot publishing. Native snapshot capture reuses the deterministic calculator through `previewPregame`, which does not persist legacy forecasts, feature snapshots, or shadow jobs. | Retain old snapshot, model-run, prediction references, and historical calibration corpora for research and existing bets. Calculator retirement is separate from retiring active legacy writes. |
| NFL `RunPregamePipelineCommand`, disposition/decision recorders, and `NflCanonicalCutoverReadinessService` | Migrated: active generation captures versioned recommendation evidence in native inputs; readiness reads those inputs and exact-revision native decisions without querying legacy forecasts or feature snapshots. Historical recorders retain legacy identities. | Register release `1.2.0` / `nfl-pregame-v3` before generating the new input contract. Preserve quarantine/missing-EPA, candidate consensus, minimum-edge, exact fresh paired-quote, and tracking-only rules. No public approval is granted by this migration. |
| `SportsPipelineRegistry` and `routes/console.php` | NFL predict/full generation now invokes the canonical-only pregame pipeline. Other sport pipelines use native generation after both canonical switches are enabled, retaining compatibility generation otherwise. CFB predict/full plans omit the unused legacy adaptive-profile writer only after this same cutover; partial-switch modes preserve it. Explicit historical calibration and historical grading remain available. Canonical daily evaluation/generation has independent per-sport pipeline gates. | After downstream dependencies migrate, remove legacy stages per sport while retaining upstream ingestion, Elo, metrics, season guards, heartbeat names, and recovery commands. Verify both direct schedules and registry-based invocation. |
| Timed NBA/CBB, WNBA/WCBB, and NFL live update actions | Migrated to `LivePredictionStore` and append-only `live_prediction_snapshots`. The selected pregame baseline is pinned at the first live observation; native run/revision lineage is retained. | Deploy the new migration before these writers. Scoreboard, API, dashboard, betting, and freshness readers use snapshots. Existing clock, overtime, clearing, rounding, and permission behavior remains. |
| MLB `UpdateLivePrediction` | Migrated to the shared live snapshot store, preserving inning/outs formulas and phase handling. | Legacy-mode pregame baselines remain available through the shared source boundary; no live legacy columns are written or read for delivery. |
| CFB `UpdateLivePrediction` | Uses its existing specialized append-only snapshots; redundant legacy-column mirror removed. | Preserve regulation/overtime/final snapshots and live-board freshness behavior. No duplicate shared-store CFB stream is created. |
| NFL prospective evidence capture | Migrated: canonical mode captures frozen recommendation inputs with explicit native capture/observation lineage; historical mode retains its existing policy. Materialization, grading and reporting separate sources. | Apply the lineage migration before code. Preserve configuration, early/late, cohort and immutable trial rules. Native evidence trials remain held pending a separate preregistration contract; retain historical compatibility for existing evidence. |
| MLB calculation diagnostics | Migrated: explain/audit commands read selected canonical outputs and frozen run/input evidence in canonical mode; explicit historical source remains available. | Retain legacy calculation diagnostics for historical forecasts. Never fill native evidence gaps from mutable odds or legacy metadata. |
| CFB pregame refresh resilience | Metric batches continue and report per-team success/failure. Only games with successfully refreshed required teams proceed; failed or ambiguous batches remain held. Bounded CFBD transient retries preserve authentication failures, optional WEPA subscription handling, and existing stored metrics on failure. | Queued dispatch is not success evidence. Worker terminal heartbeats persist run IDs, per-game outcomes, fresh-publication and reused counts; partial/failed outcomes also fail the queue job. Resumed stages recheck failed-input cohorts. Generation/release quality and betting approvals remain unchanged. |
| NFL pro-signal report | Migrated: `nfl:report-pro-signals` selects safe frozen recommendation pro-signal layers and their recorded entry context after either NFL canonical switch is enabled. Missing recorded entry fields can use only same-input frozen consensus/projections. Shared grading preserves home-margin signs, picks, winner ties, ATS/OU pushes, thresholds, tiers, reason samples and ROI proxy. | `--forecast-source=legacy` retains historical mutable entry/closing odds and CLV; native `--model-version` defaults to current. Native missing layers/entry evidence do not borrow legacy forecasts, and uncaptured closing lines leave CLV unavailable. Native missing scores remain unknown in the score-derived Brier report; this report establishes neither calibration nor betting approval. A single corpus now serves rows and empty-report diagnostics. No generation, schema, schedule, approval or rollout changes. |
| NFL accuracy report | Migrated: `nfl:analyze-predictions` uses the shared batched NFL source for frozen recommendation win probabilities, home-margin projections and analysis metadata after either NFL canonical switch is enabled. Shared scoring retains winner/tie conventions, confidence buckets, Brier/log loss, spread errors, year breakdowns and token reports. | `--forecast-source=legacy` retains historical reports and is pinned in historical readiness. Native `--model-version` defaults to current. Probability and spread eligibility are independent of quote coverage and betting qualification; missing/unsafe evidence never borrows legacy or primary canonical outputs. Native missing trust/edge metadata remains unknown and is excluded from numeric averages and trust bands. Observational reporting grants no calibration or betting approval; no schema, generation, schedule or rollout changes. |
| NFL moneyline backtest | Migrated: `nfl:backtest-moneylines` uses the shared batched NFL backtest source, frozen recommendation probability/trust and same-input paired home/away moneyline prices after either canonical switch is enabled. | `--forecast-source=legacy` preserves historical odds and snapshot fallback; historical readiness pins this source. Native `--model-version` defaults to current. Missing probability, trust, prices or safe publication evidence excludes the game; no mutable or primary-market fallback. Native limits apply after qualification. Shared scoring retains no-vig edges, thresholds, home/away selection, tie losses, payouts and ROI. Report qualification grants no approval; no writer, schema or rollout change. |
| NFL spread and totals backtests | Migrated: native mode uses one batched reader for frozen recommendation projections, same-input entry lines, confidence and total-rule metadata. Both reports share scoring math with their historical path. Either NFL canonical switch selects native inputs. | Explicit `--forecast-source=legacy` preserves historical odds/CLV and is pinned in `nfl:readiness-pass`; `--model-version` selects the native recommendation version, current by default. Native missing evidence stays excluded, per-market eligibility precedes limits, and absent confidence remains unknown. Closing lines are not captured by native inputs, so native CLV stays unavailable. Preserve signs, pushes, no-pick/equality behavior, rule directions, play ranges, watchlists and market-quality warnings. No generation, schema, cadence, approval or rollout-setting change. |
| Shared EPA-blend calibration report | Migrated: scheduled NFL/NBA/CBB/WCBB reports select canonical evidence after either sport switch is enabled. NFL reuses frozen recommendation forecasts and their own EPA metadata; basketball uses primary canonical projections and recorded output metadata. Batched source hydration and streaming accumulators replace full-corpus reads and duplicated row groups. | `--forecast-source=legacy` retains historical grades, winner flags, missing-metadata handling and optional-total denominators. Native missing EPA application evidence is an unknown cohort; no legacy or primary/recommendation mixing fills gaps. NFL `--model-version` defaults to current. Existing weekly schedules, observational caveat, model promotion and betting holds remain unchanged; no generation, schema or rollout-setting changes. |
| WNBA spread backtest | Migrated: `wnba:backtest-spreads` reads frozen canonical projections and same-input consensus entry lines through the shared basketball reader after either WNBA canonical switch is enabled. Historical/native rows share ATS, pick-type, winner, candidate-gate, bucket and disagreement calculations. | `--forecast-source=legacy` preserves mutable odds selection, stored grading fields and identical historical JSON/text output. Native winner results derive from final margins; missing quotes exclude rows and are counted before the post-eligibility limit. Missing confidence remains null, stays out of buckets and holds qualification when the spread gate is enabled; explicitly disabled gates retain their configured edge-only behavior. Preserve spread signs, equality choosing away, pushes, favorite confidence blocks, candidate ranges, season scope and full team names. Native forecasts without genuine frozen market quotes remain excluded. Report qualification grants no approval; no schema, generation, schedule or rollout change. |
| WNBA calibration report | Migrated: `wnba:report-calibration` reads frozen canonical projections and recorded confidence through the shared basketball reader when either WNBA canonical switch is enabled. Shared calculations preserve spread/total errors, signed biases, bucket boundaries and historical winner/tie reporting. | `--forecast-source=legacy` retains graded historical rows and identical JSON/text report formats; compatibility remains default when both switches are off. Native eligibility requires safe final-game forecasts with both projections, not market quotes or a legacy grade. Native limits apply after eligibility, missing confidence remains null and contributes neither numeric averages nor low-confidence buckets. Native JSON includes source/lineage and the observational caveat. No calibration approval, betting approval, generation, schedule, schema or rollout changes. |
| CBB live audit | Migrated: `cbb:backtest-live-predictions` reads immutable live snapshots paired with safe canonical pregame baselines when either CBB canonical switch is enabled. Final grading selects the latest observed live-state snapshot per completed game, excluding final markers; current coverage uses the latest observed state without bypassing newer holds. | `--forecast-source=legacy` retains the historical column audit and its output. Native rows verify exact baseline/run/game identity, publication and frozen kickoff timing, approved/retired release and pinned source. Missing metrics have separate sample counts and stay unavailable; sums/counts replace full error arrays. Game/input hydration is bounded at 25. Native final coverage counts final games, not legacy prediction identities. No writer, live formula, schema, cadence, rollout or approval change. |
| CBB spread and totals backtests | Migrated: `cbb:backtest-spreads` and `cbb:backtest-totals` use frozen native projections and same-input consensus lines when either CBB canonical switch is enabled. NBA, CBB and WNBA reports share `BasketballBacktestForecastSource` with 25-row native hydration; the former NBA-only source is removed. | `--forecast-source=legacy` retains stored and recomputed historical forecasts and legacy odds; native `--stored` never regenerates. Shared scoring within each CBB report preserves home-margin signs, spread equality choosing away, total equality choosing under, pushes, thresholds and post-eligibility limits. Missing native confidence remains unknown; missing or unsafe forecasts/markets do not fall back. CBB currently does not capture consensus quotes in its native builder, so these reports exclude forecasts without genuine frozen market evidence. NBA phase defaults remain unchanged. No schema, schedule, approval, generation or rollout changes. |
| NBA spread and totals backtests | Migrated: `nba:backtest-spreads` and `nba:backtest-totals` select verified canonical forecasts and same-input frozen consensus lines when either NBA canonical switch is enabled. Both use one shared reader with 25-row native hydration and shared historical/native scoring math. | `--forecast-source=legacy` preserves stored/recomputed historical spreads and frozen historical totals; default compatibility remains when both switches are off. Native `--stored` never regenerates forecasts. Preserve spread home-margin signs, pushes, totals passes, thresholds, season filters, totals phase defaults/exclusions, and post-eligibility limits. Missing native confidence does not create a low-confidence bucket. NBA's current canonical input builder does not capture market consensus: those forecasts remain excluded until genuine frozen quote evidence is available; never substitute mutable odds or legacy lines. No generation, schema, approval, or production setting change. |
| CFB preseason readiness | Migrated: `cfb:report-preseason-readiness` selects canonical published outputs and frozen Elo/FPI inputs when either CFB canonical switch is enabled. A single scoped source corpus serves backtests, Week 0/1 warnings, and spread-convention samples; native hydration is batched at 25. | `--forecast-source=legacy` preserves historical grades, warning rules, and sample conventions. Native backtests use completed-game results, never legacy grading fields. Existing independent signal-coverage checks remain; eligible scheduled/delayed Week 0/1 games without native forecasts receive `week_0_1_canonical_forecast_unavailable`, honoring `--fail-on-warnings`. Missing or unsafe native inputs do not fall back to legacy forecasts. No generation, schema, approval, or production setting change. |
| NFL point-projection audit | Migrated: `nfl:analyze-point-projections` selects frozen canonical recommendation margins, totals, and consensus market lines when either NFL canonical switch is enabled. Shared scoring math retains home-margin signs, implied team scores, winner/error metrics, and detailed/layer reports. | `--forecast-source=legacy` preserves historical inspection; the historical readiness replay explicitly retains that source. `--model-version` defaults to current. Native rows require valid frozen projections and matching kickoff identity; absent evidence or market lines never borrow primary canonical markets, mutable odds, or legacy rows. Native input reads are batched at 25; limits select the latest eligible generated forecasts. Layers show only captured metadata. |
| NFL reason-code audit | Migrated: `nfl:analyze-reason-codes` selects frozen canonical recommendation reasons, trust scores, and projections when either NFL canonical switch is enabled. | `--forecast-source=legacy` retains historical analysis; `--model-version` selects the native recommendation version (current by default). Missing or ineligible native evidence never borrows primary markets or legacy forecasts. Existing scope, catalog filtering, winner and spread calculations remain unchanged. Native input reads are batched in groups of 25. The historical `nfl:readiness-pass` replay explicitly retains legacy reason analysis. |
| Historical and analytical snapshot consumers | `TrustedSnapshotDataset`, `MlbPeriodFeatureBuilder`, and trusted historical reconstruction retain historical snapshot contracts. `NflCalibrationHistory` now separates native pregame calibration from explicit historical compatibility. Active NFL spread assessment now uses canonical recommendation history; `NflSpreadValidationDataset` retains explicit historical validation/replay compatibility. MLB monitoring now resolves recorded native or historical snapshot inputs as described above. | Provide source-aware adapters for active analysis and preserve historical provenance. Do not relabel reconstructed legacy rows as verified native forecasts or delete referenced tables. |
| Finalization and grading | Canonical evaluation is now independently discoverable through the queued finalization listener when `canonical_pipeline.{sport}` is enabled. Legacy graders and supported prop graders remain active for historical rows. Daily canonical evaluation commands remain recovery backstops. | Retire legacy graders only after outstanding work and their callers are accounted for. Public read switches do not select grading ownership. Existing final-clock/minimum-age guards remain in force. |

Finalization did not disable legacy writers. The subsequent NFL snapshot/readiness migration removes legacy generation from the ordered NFL pregame command and registry predict/full generation step; explicit historical generation commands and compatibility mode remain. Active mutable pregame writes are guarded after canonical cutover. Live writers use independent snapshots. A sport can retire a writer only after its replacement consumers, affected tests, coverage/readiness evidence, and rollback procedure are verified. Historical storage removal remains a separate decision.

## Internal API

The primary Vue-facing internal API is the Laravel JSON API mounted under
`/api/v2`. V1 was removed on 2026-09-25 after the owner confirmed it was used
only by the application. Former V1 URLs return 404. See
[the retirement record](api-v2-contracts-and-retirement.md).
The current v2 route matrix, filters, frontend owners, and contract-test
coverage are documented in
[api-v2-reference.md](/Users/bey/Herd/github/picksports/docs/api-v2-reference.md).
The generated route-level OpenAPI artifact is
[openapi-v2.json](/Users/bey/Herd/github/picksports/docs/openapi-v2.json).

Route bootstrap:
- [routes/api.php](/Users/bey/Herd/github/picksports/routes/api.php)
- [routes/api-v2.php](/Users/bey/Herd/github/picksports/routes/api-v2.php)

Important pattern:
- `routes/api-v2.php` defines the current sport contract under `/api/v2/sports/{sport}`
- sport capability and model resolution should happen through the v2 context layer

### Auth and Access Model

Auth endpoints:
- `POST /api/v2/auth/login`
- `POST /api/v2/auth/passkeys/options`
- `POST /api/v2/auth/passkeys/verify`
- `GET /api/v2/auth/me`
- `POST /api/v2/auth/logout`
- `POST /api/v2/auth/logout-all`

Sports data access model:
- sport data endpoints require `v2.auth` and `v2.sport-api-access`
- prediction, team-metric, injury, and trends access often also depends on permissions such as `view-{sport}-predictions`
- prediction resources are field-gated by subscription tier / permission, not just endpoint access

That means:
- the same endpoint can return different field sets depending on the authenticated user
- the frontend must tolerate partial prediction payloads

### Generic Sport Route Contract

For each supported sport, the v2 API includes these categories:

Teams:
- `GET /api/v2/sports/{sport}/teams`
- `GET /api/v2/sports/{sport}/teams/{team}`
- `GET /api/v2/sports/{sport}/teams/{team}/trends`
- `GET /api/v2/sports/{sport}/teams/{team}/metrics`
- `GET /api/v2/sports/{sport}/teams/{team}/games`

Players:
- `GET /api/v2/sports/{sport}/players`
- `GET /api/v2/sports/{sport}/players/{player}`
- `GET /api/v2/sports/{sport}/teams/{team}/players`

Games:
- `GET /api/v2/sports/{sport}/games`
- `GET /api/v2/sports/{sport}/games/{game}`
- `GET /api/v2/sports/{sport}/games/{game}/prediction`
- `GET /api/v2/sports/{sport}/games/{game}/depth-charts`
- `GET /api/v2/sports/{sport}/games/{game}/player-props`

Predictions:
- `GET /api/v2/sports/{sport}/predictions`
- `GET /api/v2/sports/{sport}/predictions/{prediction}`
- `GET /api/v2/sports/{sport}/predictions/available-dates`
- `GET /api/v2/sports/{sport}/predictions/available-seasons`

Team metrics:
- `GET /api/v2/sports/{sport}/metrics/teams`
- `GET /api/v2/sports/{sport}/metrics/teams/available-seasons`

Stats, injuries, and markets:
- `GET /api/v2/sports/{sport}/stats/team`
- `GET /api/v2/sports/{sport}/stats/player`
- `GET /api/v2/sports/{sport}/stats/team/season-averages`
- `GET /api/v2/sports/{sport}/leaderboards/players`
- `GET /api/v2/sports/{sport}/injuries`
- `GET /api/v2/sports/{sport}/markets/player-props`
- `GET /api/v2/sports/{sport}/markets/futures`

Capability-specific additions:
- NFL research, live snapshots, matchup signals and market history
- CFB live betting
- CBB/WCBB tournament forecasts
- NBA/MLB playoff forecasts
- odds-backed player props for enabled sports

### Internal API Filtering Rules

Prediction index filtering in [SportPredictionQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportPredictionQuery.php):
- `season`
- `season_type`
- `week`
- `from_date`
- `to_date`

Team trends filtering in [SportTeamTrendQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportTeamTrendQuery.php):
- `games`
- `season`
- `season_type`
- `before_date`

Team metrics filtering in [SportTeamMetricQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportTeamMetricQuery.php):
- `season`
- `season_type`
- sport-specific query preparation in the shared query service

### Resource Serialization Pattern

Controllers generally return Eloquent models loaded with relations.
Resources shape the actual API contract.

Important examples:
- [app/Http/Resources/MLB/GameResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/MLB/GameResource.php)
- [app/Http/Resources/NFL/GameResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/NFL/GameResource.php)
- [app/Http/Resources/MLB/PredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/MLB/PredictionResource.php)
- [app/Http/Resources/NFL/PredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/NFL/PredictionResource.php)
- shared prediction helpers in [AbstractPredictionResource.php](/Users/bey/Herd/github/picksports/app/Http/Resources/Sports/AbstractPredictionResource.php)

Important contract note:
- `games/{game}` returns the base game payload plus loaded relations and any computed attributes attached by the controller
- `games/{game}/prediction` returns the prediction resource shape, which is sport-specific and permission-gated
- not all sports expose identical prediction fields

Examples:
- MLB prediction resource exposes:
  - `predicted_spread`
  - `predicted_total`
  - `win_probability`
  - `home_team_elo`
  - `away_team_elo`
  - `home_pitcher_elo`
  - `away_pitcher_elo`
- NFL prediction resource exposes:
  - `predicted_spread`
  - `predicted_total`
  - `win_probability`
  - `home_elo`
  - `away_elo`
  - `betting_value`

### byGame Prediction Contract Caveat

The `games/{game}/prediction` endpoint deserves special care.

The shared controller supports both collection-style and single-object responses.
Some sports override this behavior.

Example:
- [app/Http/Controllers/Api/MLB/PredictionController.php](/Users/bey/Herd/github/picksports/app/Http/Controllers/Api/MLB/PredictionController.php)
  forces `returnFirstPredictionOnly()` to `true`
- NFL currently uses shared behavior without that override

Before changing any game-page prediction fetch path, confirm whether the endpoint returns:
- a single prediction object
- a list containing one prediction

### Frontend Consumers of the Internal API

The frontend talks to product data through
[useApiV2Client.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useApiV2Client.ts),
which wraps `fetchJson` from
[useApiClient.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useApiClient.ts).

Main game-page consumers:
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
  Uses:
  - `api.games.show()`
  - `api.predictions.forGame()`
  - `api.stats.teams()`
  - `api.stats.players()`
  - `api.teams.metrics()`
  - `api.teams.games()`
  - `api.teams.trends()`

- [useMlbGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useMlbGamePage.ts)
  Does a more bespoke MLB flow on top of `useApiV2Client()`.

- [useNflGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useNflGamePage.ts)
  Does a bespoke NFL flow on top of `useApiV2Client()`.

- [useCfbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useCfbDetailedGamePage.ts)
  Does a bespoke CFB flow similar to NFL on top of `useApiV2Client()`.

Predictions list pages:
- [SportPredictions.vue](/Users/bey/Herd/github/picksports/resources/js/components/SportPredictions.vue)
  Uses:
  - `api.predictions.index()`
  - `api.predictions.availableDates()`
  - `api.predictions.availableSeasons()`

Team metrics pages:
- built from config in [sport-team-metrics-configs.ts](/Users/bey/Herd/github/picksports/resources/js/config/sport-team-metrics-configs.ts)
  and call `api.metrics.teams()`

### Internal API Data Flow

The normal flow is:

1. External source ingestion
   - ESPN sync actions populate sport tables:
     - games
     - teams
     - players
     - stats
     - plays
     - injuries
   - Odds API sync actions populate:
     - `odds_api_event_id`
     - `odds_data`
     - player props / mappings where applicable

2. Derived calculations
   - Elo commands/actions populate `elo_ratings`
   - team metrics commands/actions populate `team_metrics`
   - prediction commands/actions populate `predictions`
   - trends are usually computed on demand rather than persisted as first-class rows

3. API assembly
   - controller loads model and relations
   - shared abstract controller applies filtering and caching
   - resource serializes the public/internal JSON contract
   - field-level gating may hide premium prediction fields

4. Frontend composition
   - composables call multiple API endpoints
   - page-specific hooks normalize sport-specific payloads
   - shared game-page components render the assembled state

### Caching in the Internal API

Shared API controllers use [SportsViewCache.php](/Users/bey/Herd/github/picksports/app/Support/SportsViewCache.php).

Important cache segments include:
- `predictions_index`
- `team_metrics_index`
- `team_trends`
- `team_games_by_team`

This matters when debugging “the DB has it but the UI doesn’t.”
Sometimes the issue is:
- stale cache segment
- stale browser state
- page-specific payload normalization
not missing database rows.

### Internal API Debugging Checklist

When something looks wrong on a page:

1. Check the raw game row in the DB.
2. Check the raw prediction / metric row in the DB.
3. Check the API resource response at `/api/v2/sports/{sport}/games/{id}`.
4. Check `/api/v2/sports/{sport}/games/{id}/prediction`.
5. Check whether the frontend uses the shared loader or a sport-specific loader.
6. Check cache segments if the DB and API are correct but the page is stale.

### Internal API Contract Risks To Watch

- Sport resources are similar but not identical.
- Prediction resources are permission-gated.
- Some pages use shared loaders, others use bespoke sport loaders.
- Some endpoints return arrays in one sport and single objects in another.
- `season_type` support is not uniform unless the underlying tables actually store it.

## Shared Frontend Game Page Flow

The main game page UI is shared.

Key files:
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
  Shared loader for game detail, prediction, team stats, player stats, recent games, and team trends.

- [useSportDetailedPageProps.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useSportDetailedPageProps.ts)
  Shared prop assembler for detailed game pages.

- [useSportGameLayout.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useSportGameLayout.ts)
  Shared layout/breadcrumb config.

- [SportDetailedGamePage.vue](/Users/bey/Herd/github/picksports/resources/js/components/game-page/SportDetailedGamePage.vue)
  Shared shell for matchup hero, linescore, prediction summary, trends, and now matchup context.

Sport-specific wrappers:
- [useMlbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useMlbDetailedGamePage.ts)
- [useNflDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useNflDetailedGamePage.ts)
- [useBasketballDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useBasketballDetailedGamePage.ts)
- [useCfbDetailedGamePage.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useCfbDetailedGamePage.ts)

## Per-Sport Model Surface

### MLB

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Team.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/MLB/TeamMetric.php)
- [EloRating.php](/Users/bey/Herd/github/picksports/app/Models/MLB/EloRating.php)
- [PitcherEloRating.php](/Users/bey/Herd/github/picksports/app/Models/MLB/PitcherEloRating.php)
- [Player.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Player.php)
- [PlayerStat.php](/Users/bey/Herd/github/picksports/app/Models/MLB/PlayerStat.php)

Important field semantics:
- `teams.league` and `teams.division` are the alignment fields, not `conference`
- `games.probable_home_pitcher_espn_id` / `probable_away_pitcher_espn_id` are meaningful pregame starter fields
- `player_stats.stat_type` distinguishes batting vs pitching rows
- `team_metrics` now support `season_type` rows

Important operational note:
- MLB opening-day handling is sensitive to local-date conversion and season-type classification

### NFL

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Team.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/NFL/TeamMetric.php)
- [Player.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Player.php)
- [PlayerStat.php](/Users/bey/Herd/github/picksports/app/Models/NFL/PlayerStat.php)

Important field semantics:
- `teams.conference` and `teams.division` are meaningful NFL alignment fields
- there is no dedicated pregame `starting_qb` field on `nfl_games`
- QB inference currently has to come from player stats / recent games if needed
- `games.neutral_site` exists and matters for context splits

### CFB

Primary models:
- [Team.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Team.php)
- [TeamSeasonAffiliation.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamSeasonAffiliation.php)
- [Game.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Game.php)
- [Prediction.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Prediction.php)
- [TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamMetric.php)
- [FpiRating.php](/Users/bey/Herd/github/picksports/app/Models/CFB/FpiRating.php)

Important field semantics:
- `teams.conference` / `teams.division` are not enough by themselves across seasons
- season-aware alignment should prefer `team->seasonAffiliation($season)`
- `games.conference_game` exists and is more trustworthy than inferred “same conference” in some cases
- `games.postseason_round` matters for postseason labeling

### NBA / WNBA

Primary models:
- [app/Models/NBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Team.php)
- [app/Models/NBA/Game.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Game.php)
- [app/Models/NBA/Prediction.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Prediction.php)
- [app/Models/NBA/TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/NBA/TeamMetric.php)
- [app/Models/WNBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Team.php)
- [app/Models/WNBA/Game.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Game.php)

Important field semantics:
- `conference` and `division` are real alignment fields
- basketball game pages use the shared `useDetailedGameData` path, not bespoke per-sport game detail loaders

### CBB / WCBB

Primary models:
- [app/Models/CBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Team.php)
- [app/Models/CBB/Game.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Game.php)
- [app/Models/CBB/Prediction.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Prediction.php)
- [app/Models/CBB/TeamMetric.php](/Users/bey/Herd/github/picksports/app/Models/CBB/TeamMetric.php)
- [app/Models/WCBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Team.php)
- [app/Models/WCBB/Game.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Game.php)

Important field semantics:
- `conference` is usually meaningful
- `division` is often an NCAA classification / level field, not a pro-style intraconference division
- do not assume “division record” is product-valid for these sports without confirming the underlying data meaning

## Prediction / Team Metrics / Trends Pipelines

### Predictions

Sport actions:
- `app/Actions/{Sport}/GeneratePrediction.php`
- `app/Console/Commands/{Sport}/GeneratePredictionsCommand.php`

Game page prediction endpoint:
- `/api/v2/sports/{sport}/games/{game}/prediction`

Important note:
- the frontend usually expects a single prediction object for a game page
- prediction index endpoints may return collections

### Team Metrics

Sport actions:
- `app/Actions/{Sport}/CalculateTeamMetrics.php`
- `app/Console/Commands/{Sport}/CalculateTeamMetricsCommand.php`

Important note:
- the shared team-metric controller now supports true `season_type` filtering when the table actually stores that column
- MLB team metrics now support season-type-specific rows

### Trends

Sport actions:
- `app/Actions/{Sport}/CalculateTeamTrends.php`
- shared base: [AbstractCalculateTeamTrends.php](/Users/bey/Herd/github/picksports/app/Actions/Trends/AbstractCalculateTeamTrends.php)

Important note:
- trends are calculated from prior games and should use `season`, `season_type`, and `before_date` where relevant
- `TrendForecastSource` supplies model context to all seven sports. After canonical read cutover, it batches verified published pregame revisions through `CanonicalShadowInputs`, reads frozen primary markets and Elo, and does not query legacy predictions. Missing, withdrawn, unsafe, or kickoff-time publications withhold model context while retaining observed scores and statistics.
- Native model margins use home-minus-away values. Compatibility mode preserves the existing sport-specific legacy conventions. Collectors no longer access legacy prediction relations directly; totals, streaks, situational comparisons and opponent strength share the same normalized boundary.
- NFL team profiles apply the same publication cutoff and retain their strict legacy pre-kickoff/recomputation guard. Existing windows, UTC filters, league ranks, field coverage and nonoverlapping comparisons remain unchanged. Outer trend cache keys include canonical read mode to isolate cutovers. Large frozen input graphs are loaded in bounded batches and released after scalar context/lineage extraction.

## ESPN / Odds Ingest Surface

ESPN ingestion:
- `app/Actions/ESPN/{Sport}/SyncTeams.php`
- `SyncGames.php`
- `SyncGamesFromSchedule.php` where applicable
- `SyncGamesFromScoreboard.php`
- `SyncGameDetails.php`
- `SyncPlayerStats.php`
- `SyncTeamStats.php`
- `SyncPlays.php`
- `SyncPlayerInjuries.php`

Odds ingestion:
- `app/Actions/OddsApi/{Sport}/SyncOddsForGames.php`
- `SyncPlayerPropsForGames.php` where applicable

### ESPN Endpoint Availability Matrix

This section is based on direct endpoint checks, not assumptions from field names.

Verified usable depth-chart endpoints:
- `NFL`
  - team core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{season}/teams/{teamId}/depthcharts`
- `NBA`
  - team core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/basketball/leagues/nba/seasons/{season}/teams/{teamId}/depthcharts`
- `MLB`
  - team core payload exposes a `depthCharts` `$ref`
  - direct core depth chart endpoint works:
    - `https://sports.core.api.espn.com/v2/sports/baseball/leagues/mlb/seasons/{season}/teams/{teamId}/depthcharts`

Verified usable coach endpoints:
- `NFL`
  - season coach list endpoint works and returns one coach ref per team-season snapshot:
    - `https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{season}/coaches?limit=50`
  - resolved season coach payloads include:
    - coach id / uid / firstName / lastName / displayName when available
    - `team.$ref` pointing to the ESPN season team
    - `experience`
    - `records[].record.$ref` for season-type records, including regular season `types/2`
  - team season payloads expose a `coaches.$ref`:
    - `https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{season}/teams/{teamId}`
  - team coach list endpoint works but should be treated as secondary:
    - `https://sports.core.api.espn.com/v2/sports/football/leagues/nfl/seasons/{season}/teams/{teamId}/coaches`

Important coach-ingest rule:
- use the season coach list as the primary league snapshot
- resolve each coach ref and map the assignment from the resolved `team.$ref`
- use stored `nfl_team_coach_seasons` rows for year-over-year new-head-coach detection
- keep config overrides available for manual priors, but do not rely on config as the source of truth once ESPN coach history is synced

Not currently reliable from the tested public/core endpoints:
- `WNBA`
  - tested direct depth chart endpoint returned `500`
  - team core payload did not expose a matching `depthCharts` ref in the same way as NBA
- `CFB`
  - tested direct depth chart endpoint returned `500`
  - team core payload did not expose a matching `depthCharts` ref
- `CBB`
  - tested direct depth chart endpoint returned `400`
  - team core payload did not expose a matching `depthCharts` ref
- `WCBB`
  - tested direct depth chart endpoint returned `400`
  - team core payload did not expose a matching `depthCharts` ref

Important rule:
- do not assume every ESPN sport has a stable `depthcharts` contract
- prefer discovering `depthCharts.$ref` from the team core object when available
- when the ref is missing or the endpoint errors, treat depth-chart ingest for that sport as unsupported until re-verified

### ESPN MLB Probable Starter Availability

MLB probable starters are available from ESPN scheduled-game payloads.

Verified locations:
- site scoreboard:
  - `https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/scoreboard?dates={yyyymmdd}`
- site summary:
  - `https://site.api.espn.com/apis/site/v2/sports/baseball/mlb/summary?event={eventId}`

Verified payload shape:
- `competitors[].probables[]`
- probable starter entries include:
  - `abbreviation: "SP"`
  - `name: "probableStartingPitcher"`
  - `playerId`
  - nested `athlete.id`

Codebase note:
- the current MLB ESPN ingest already uses this data in:
  - [SyncGames.php](/Users/bey/Herd/github/picksports/app/Actions/ESPN/MLB/SyncGames.php)
  - [SyncGameDetails.php](/Users/bey/Herd/github/picksports/app/Actions/ESPN/MLB/SyncGameDetails.php)
- `games.probable_home_pitcher_espn_id` / `probable_away_pitcher_espn_id` are therefore grounded in real ESPN payloads, not a heuristic

### Recommended ESPN Ingestion Plan By Sport

This is the recommended storage/ingest plan based on the endpoints currently verified.

#### MLB

Use:
- game ingest from existing scoreboard / summary flows
- probable starters from `competitors[].probables[]`
- team depth charts from the core `depthCharts` ref

Recommended stored data:
- probable starter ESPN player id
- probable starter handedness / display name if present
- team depth-chart slots by position
- depth-chart ordering / rank
- source timestamps for freshness checks

Recommendation:
- keep probable-starter ingest as the primary pregame pitching source
- treat depth charts as supplemental roster/context data, not a replacement for `probables`

#### NFL

Use:
- existing game/team/player ingest
- core team `depthcharts` endpoint

Recommended stored data:
- player slot by offensive / defensive / special teams position
- depth rank within position
- starter flag if it can be derived from the top slot
- source timestamps

Recommendation:
- this is a good candidate for first-class depth-chart ingest
- useful for QB1/RB1/WR depth, injury replacement context, and lineup-aware prediction features

#### NBA

Use:
- existing game/team/player ingest
- core team `depthcharts` endpoint

Recommended stored data:
- position slot
- depth rank
- likely starter group
- source timestamps

Recommendation:
- implement as lineup context, not as a hard projection input by default
- useful for injury-adjusted roster context and game-page presentation

#### WNBA

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not build production ingest around depth charts yet
- re-verify ESPN availability later or use a separate lineup source if WNBA starter/depth data becomes important

#### CFB

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not assume ESPN depth-chart support
- if starter context is needed, build a separate CFB-specific approach
- especially avoid presenting “starting QB” as explicit unless the source is explicit

#### CBB / WCBB

Use:
- existing game/team/player ingest only for now

Recommendation:
- do not build against the tested `depthcharts` pattern
- if lineup context becomes important, use a different source or a sport-specific ingest strategy

### Suggested Implementation Order

1. `MLB`
   - formalize current probable-starter ingest contract
   - optionally add team depth-chart ingest behind a separate sync action
2. `NFL`
   - add persistent team depth-chart storage and sync
3. `NBA`
   - add persistent team depth-chart storage and sync
4. `WNBA`, `CFB`, `CBB`, `WCBB`
   - defer until ESPN exposes a stable contract or a better source is chosen

### Data-Model Guidance For Depth Charts

If implemented, depth-chart storage should be modeled as source data, not inferred analytics.

Suggested fields:
- `sport`
- `team_id`
- `season`
- `season_type` if the source supports meaningful seasonal separation
- `espn_athlete_id`
- `position_code`
- `position_name`
- `slot_order`
- `depth_rank`
- `is_starter`
- `source`
- `source_updated_at`
- raw source payload for debugging

Operational rule:
- depth-chart data should be replaceable on every sync
- probable-starter / QB logic should prefer explicit game-level source data over team-level depth charts when both exist

## Sport Config Files

Primary sport config lives in:
- [config/mlb.php](/Users/bey/Herd/github/picksports/config/mlb.php)
- [config/nfl.php](/Users/bey/Herd/github/picksports/config/nfl.php)
- [config/cfb.php](/Users/bey/Herd/github/picksports/config/cfb.php)
- [config/nba.php](/Users/bey/Herd/github/picksports/config/nba.php)
- [config/wnba.php](/Users/bey/Herd/github/picksports/config/wnba.php)
- [config/cbb.php](/Users/bey/Herd/github/picksports/config/cbb.php)
- [config/wcbb.php](/Users/bey/Herd/github/picksports/config/wcbb.php)

Use config as source of truth for:
- statuses
- season types
- Elo defaults
- team-metric formulas
- trend thresholds
- prediction blend weights

## Field Semantics That Should Not Be Assumed

This is the section to check before building cross-sport “generic” features.

### 1. `division` does not mean the same thing across sports

- `NFL`, `NBA`, `WNBA`, `MLB`: usually real alignment grouping
- `MLB`: use `league` + `division`, not `conference`
- `CBB`, `WCBB`: `division` may effectively mean NCAA level/classification, not “same division as tonight’s opponent”
- `CFB`: season-aware affiliation may live in `TeamSeasonAffiliation`, not the base team row

### 2. `conference` does not automatically mean “conference record is valid”

You may need to consider:
- season-aware affiliations in CFB
- explicit `conference_game` flag in CFB games
- whether the sport actually organizes regular standings that way

### 3. Starter identity is asymmetric by sport

- MLB: probable starters are explicit on the game row
- NFL/CFB: there is currently no dedicated pregame starting-QB field on the game model
- Any “record vs QB” logic in football is inferred unless a dedicated starter field is added later

### 4. `season_type` is operationally important

It affects:
- trend samples
- opening-day logic
- MLB schedule classification
- team metrics storage and retrieval
- prediction carryover behavior

## Recent MLB Lessons Already Learned

These are recent, concrete codebase lessons that should be preserved.

### MLB date handling
- MLB `game_date` must be stored/grouped by local game date, not raw UTC rollover

### MLB season type
- spring training and regular season cannot be mixed in trends or opening-day model inputs
- season-type normalization belongs in ingest, not only in cleanup scripts

### MLB opening-day carryover
- opening-day Elo/predictions should use prior-season carryover, not spring-training samples

### MLB team metrics
- `mlb_team_metrics` now need season-type-aware semantics

## Current Matchup Context Caveat

The new matchup-context work should be treated with these rules:

- head-to-head, overall record, current role record, and time-of-day record are generally safe cross-sport
- conference/division splits must be sport-aware
- MLB alignment should be league/division, not conference/division
- CBB/WCBB division record should not be shown unless the underlying field is confirmed product-valid
- CFB alignment should prefer season affiliation and/or explicit `conference_game` data
- football `record vs QB` is currently inferred, not explicitly modeled

## Recommended Workflow Before Cross-Sport Feature Work

1. Check this document.
2. Read the actual model(s) for the target sport.
3. Read the shared abstract controller/resource involved.
4. Confirm field semantics in config and tests.
5. Only then generalize across sports.

## Files To Re-Read First For Sports-Domain Changes

Shared:
- [routes/api-v2.php](/Users/bey/Herd/github/picksports/routes/api-v2.php)
- [useApiV2Client.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useApiV2Client.ts)
- [SportGameQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportGameQuery.php)
- [SportPredictionQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportPredictionQuery.php)
- [SportTeamTrendQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportTeamTrendQuery.php)
- [SportTeamMetricQuery.php](/Users/bey/Herd/github/picksports/app/Services/Api/V2/SportTeamMetricQuery.php)
- [useDetailedGameData.ts](/Users/bey/Herd/github/picksports/resources/js/composables/useDetailedGameData.ts)
- [SportDetailedGamePage.vue](/Users/bey/Herd/github/picksports/resources/js/components/game-page/SportDetailedGamePage.vue)

Sport-specific first reads:
- MLB:
  [app/Models/MLB/Game.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Game.php)
  [app/Models/MLB/Team.php](/Users/bey/Herd/github/picksports/app/Models/MLB/Team.php)
  [app/Actions/MLB/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/GeneratePrediction.php)
  [app/Actions/MLB/CalculateTeamMetrics.php](/Users/bey/Herd/github/picksports/app/Actions/MLB/CalculateTeamMetrics.php)

- NFL:
  [app/Models/NFL/Game.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Game.php)
  [app/Models/NFL/Team.php](/Users/bey/Herd/github/picksports/app/Models/NFL/Team.php)
  [app/Actions/NFL/GeneratePrediction.php](/Users/bey/Herd/github/picksports/app/Actions/NFL/GeneratePrediction.php)
  [app/Actions/NFL/CalculateTeamMetrics.php](/Users/bey/Herd/github/picksports/app/Actions/NFL/CalculateTeamMetrics.php)

- CFB:
  [app/Models/CFB/Game.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Game.php)
  [app/Models/CFB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CFB/Team.php)
  [app/Models/CFB/TeamSeasonAffiliation.php](/Users/bey/Herd/github/picksports/app/Models/CFB/TeamSeasonAffiliation.php)

- NBA / WNBA:
  [app/Models/NBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/NBA/Team.php)
  [app/Models/WNBA/Team.php](/Users/bey/Herd/github/picksports/app/Models/WNBA/Team.php)

- CBB / WCBB:
  [app/Models/CBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/CBB/Team.php)
  [app/Models/WCBB/Team.php](/Users/bey/Herd/github/picksports/app/Models/WCBB/Team.php)

## Maintenance Note

If a future change reveals a field-semantics mismatch, add it here immediately.

This document is meant to reduce repeated mistakes, especially around:
- alignment semantics
- starter identity assumptions
- season-type behavior
- shared-vs-sport-specific abstractions
