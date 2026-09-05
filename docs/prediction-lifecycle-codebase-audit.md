# Prediction lifecycle codebase audit and migration plan

Last reviewed: September 3, 2026.

Status: canonical lifecycle implementation complete for all seven sports. CBB, CFB, MLB, NBA, NFL, WCBB, and WNBA now have generation, evaluation, readiness reporting, and feature-gated API reads; production observation, backfill, cutover, and measured legacy removal remain operational work. No legacy code or production data has been removed.

September 3 hardening for CFB Week 1 closes the Week 0 revision ambiguity: numeric market outputs are normalized before hashing, draft outputs are re-hashed before publication, pregame publication is rejected at or after kickoff, and published/superseded/withdrawn revisions and their markets remain immutable. CFB generation, evaluation, and readiness reporting can now be scoped to a specific week. The production procedure is documented in `docs/operations/cfb-week-1-provenance-runbook.md`.

## Decision

PickSports should use one prediction lifecycle for every sport:

```text
Canonical Event
→ Immutable Input Snapshot
→ Approved Calculation Release + Calculation Run
→ Immutable Prediction Revision
→ Evaluation
```

Research and training are a separate lifecycle. They create approved calculation releases; they are not steps that every prediction must repeat:

```text
Research question
→ Point-in-time dataset
→ Experiment / backtest
→ Training or rules calibration
→ Evaluation
→ Approved calculation release
```

## Implementation status

Foundation batch implemented on August 13, 2026:

- Added `calculation_releases` and `calculation_release_components` for frozen rules, ML, and hybrid definitions.
- Added immutable `event_input_snapshots` with source timing, safety status, canonical content hashes, and canonical event identity.
- Added idempotent `calculation_runs` with explicit execution status, output hashes, diagnostics, and failure records.
- Evolved canonical `predictions` into revision-ready records with calculation-run identity, phase, publication state, supersession, and output hashes.
- Separated generation from publication. A successful run creates a draft revision; publication validates the run/output hash and supersedes the prior published revision atomically.
- Added typed snapshot, release, market, and output DTOs plus shared snapshot-builder and pure-calculator contracts.
- Added a canonical prediction read repository and mapper that consume stored markets instead of calculating values during serialization.
- Added immutability, effective-release selection, historical retired-release selection, idempotency, cutoff-safety, rollback, and failure-path tests.

WNBA pilot implemented:

- `WnbaInputSnapshotBuilder` captures the linked canonical event, teams, current Elo evidence, point-in-time team metrics, and active injury evidence before the event cutoff.
- `WnbaCalculator` is database-free and consumes only the immutable snapshot and frozen release configuration.
- Canonical spread markets use an explicit sportsbook home-line convention: a favored home team has a negative line. Home-margin evidence remains in output metadata.
- `GenerateCanonicalPrediction` writes only the canonical lifecycle. It does not create or update `wnba_predictions`.
- `wnba:register-calculation-release` explicitly registers and approves the frozen WNBA rules release.
- `wnba:generate-canonical-predictions` generates draft or published canonical WNBA revisions idempotently.
- `sport_event_results` records official results as immutable revisions. Corrected scores append a new result and preserve the prior source record.
- Canonical `prediction_evaluations` now reference an exact prediction, event, and result revision. Winner, Brier, log-loss, spread-error, and total-error metrics are computed once and stored.
- `wnba:evaluate-canonical-predictions` evaluates the exact pregame revision published by event start. Repeating the command with unchanged scores is idempotent; corrected scores append an evaluation revision.
- The v2 WNBA prediction API has a canonical reader selected by `PREDICTION_LIFECYCLE_WNBA_CANONICAL_READS`. It admits only successful runs backed by verified pregame snapshots and approved or retired releases.
- The canonical resource maps stored markets and evaluations. It does not run betting-value, odds-blending, or prediction calculations while serving a request.
- `wnba:report-canonical-cutover-readiness` reports missing safe predictions, unsafe lineage, duplicate published revisions, and missing final-game evaluations. `--fail-on-not-ready` makes it usable as a deployment gate.
- The scheduler can shadow-run canonical generation at `02:05` and evaluation at `00:05` when `PREDICTION_LIFECYCLE_WNBA_CANONICAL_PIPELINE=true`.

Both WNBA lifecycle switches default to false. The legacy generator, grader, and API remain active until the canonical pipeline has run in observation mode and the readiness report passes. The intended deployment order is migration, release registration, canonical shadow generation/evaluation, readiness verification, API reader cutover, and then legacy schedule removal. No production database migration or production release activation was performed as part of this implementation batch.

NBA pilot implemented:

- Added a reusable basketball snapshot builder that captures canonical event identity, Elo, point-in-time team metrics, and active injuries without reading a legacy prediction.
- NBA rules run through the same deterministic market calculator and canonical spread convention as WNBA, with an independently frozen NBA release configuration.
- `nba:register-calculation-release`, `nba:generate-canonical-predictions`, and `nba:evaluate-canonical-predictions` cover the release-to-evaluation lifecycle without writing `nba_predictions`.
- The NBA and WNBA API cutovers now share one provenance-enforcing query and one stored-output resource instead of duplicating read-time calculations.
- NBA and WNBA final-game evaluation share one basketball event evaluator, while corrected results still append sport-neutral result and evaluation revisions.
- `nba:report-canonical-cutover-readiness` uses the shared readiness service to report unsafe lineage, missing predictions, duplicate publication, and missing evaluation coverage.
- `PREDICTION_LIFECYCLE_NBA_CANONICAL_PIPELINE` and `PREDICTION_LIFECYCLE_NBA_CANONICAL_READS` default to false. The optional canonical jobs run after their legacy counterparts during observation mode.

Both NBA lifecycle switches also default to false. NBA follows the same deployment order as WNBA and does not require exact legacy formula parity; its target is a smaller, reproducible calculator whose inputs and configuration can be replayed exactly.

CBB and WCBB batch implemented:

- Extracted the deterministic market engine into `CanonicalBasketballCalculator`; all four basketball sports now use thin sport adapters rather than inheriting from a WNBA-named implementation.
- Extracted release creation and evidence validation into `CanonicalBasketballReleaseRegistrar`, with typed frozen definitions per sport.
- Added CBB/WCBB point-in-time snapshots that use the shared event, Elo, team-metric, and injury contract while respecting that college team metrics are season-level and do not have a `season_type` column.
- Added explicit release registration, canonical generation, immutable result evaluation, and readiness commands for both college sports.
- Expanded the strict canonical API query, stored-output resource, evaluator, and readiness service to CBB and WCBB.
- Added `PREDICTION_LIFECYCLE_CBB_CANONICAL_*` and `PREDICTION_LIFECYCLE_WCBB_CANONICAL_*` switches. All four switches default to false, and shadow jobs run immediately after their legacy grade/generate jobs when enabled.
- Added paired lifecycle tests that execute release freezing, idempotent commands, evaluation, readiness, and API cutover behavior independently for CBB and WCBB.

CBB and WCBB follow the same gated deployment order as the professional basketball pilots. Tournament forecasts remain a separate downstream product and are not folded into the game-prediction lifecycle.

CFB and NFL batch implemented:

- Added a shared football snapshot contract over canonical event identity, Elo, season metrics, scoring rates, turnovers, recent form, fatigue, and active injury evidence.
- Added `CanonicalFootballCalculator`, a smaller database-free rules engine. It intentionally excludes mutable sportsbook blending, live web research, AI narratives, and training-time artifact selection from request-time serving.
- CFB can use frozen FPI/power-rating evidence captured in its snapshot; NFL can use its frozen predictive rating. Missing optional ratings fall back to the common Elo and scoring model.
- Generalized the point-in-time snapshot base, immutable final-game evaluator, and rules-release registrar so basketball and football share lifecycle mechanics without cross-sport inheritance names.
- Added release registration, generation, evaluation, readiness, strict API reads, and feature-gated shadow schedules for CFB and NFL.
- Added reusable canonical command bases so new sport commands define only their game, action, registrar, and readiness adapters.
- Added paired CFB/NFL lifecycle tests for frozen configuration, legacy-write isolation, command idempotency, evaluation, readiness, and API cutover.

Both football pipeline/read switches default to false. Sourced NFL game-context research and all training/shadow-model workflows remain separate research inputs; they may influence a future approved hybrid release only after being frozen into its release and snapshot contracts.

MLB batch implemented:

- Added an MLB-specific immutable snapshot over canonical event/team identity, team run production and prevention, resolved probable starter identity and Elo, venue, and point-in-time weather.
- Added a pure MLB rules calculator that blends frozen team/pitcher Elo with team run rates, then applies frozen park and weather rules. It does not read odds, current tables, or mutable research state.
- Missing starter evidence falls back explicitly to the release's league-average pitcher rating and remains visible in the snapshot; it is not silently reconstructed during calculation.
- Added MLB release registration, canonical generation, immutable result evaluation, readiness reporting, strict API reads, and feature-gated shadow schedules.
- Added MLB lifecycle tests covering frozen pitcher/weather evidence, legacy-write isolation, idempotency, evaluation, readiness, and API cutover.

All seven sports now implement the target event-to-evaluation lifecycle. This completes the code-construction phase, not production cutover. The required operational sequence remains: deploy migrations, register releases, shadow-run, backfill safe events, evaluate completed events, pass readiness reports, enable canonical reads one sport at a time, observe rollback windows, and only then remove legacy writers/readers according to the deprecation inventory below.

The implementation intentionally optimizes the new canonical behavior instead of requiring exact legacy-output parity. Historical data mapping, rollback, and usage gates remain required, but accidental legacy conventions are not treated as target requirements.

This audit covers the complete codebase surface affected by that decision: all seven sport prediction generators, live prediction writers, grading paths, research and ML services, canonical tables, API and web readers, betting references, scheduled commands, data migrations, and tests.

The inventory covered 2,288 files under `app`, `database`, `routes`, `config`, `tests`, and `docs`. Direct legacy prediction-model references appear in 162 PHP files: 79 feature tests, 33 commands, 23 services, 16 actions, nine HTTP-layer files, one job, and one enum. Those counts define the initial migration surface; indirect database, relation, and frontend references are included in the findings below.

## Executive assessment

The repository has many of the required components, but they are connected in the wrong order.

The current primary flow is:

```text
Provider game
→ sport-specific game
→ calculate from mutable tables
→ overwrite sport-specific prediction
→ record a model run and combined feature/output snapshot afterward
→ optionally backfill a canonical prediction
→ grade the sport-specific prediction
→ record an evaluation using table-name polymorphism
```

The highest-priority findings are:

1. Canonical predictions are not part of normal prediction generation or any public read path. They are populated only by a manual backfill.
2. There is no shared `calculation_releases` registry, so no sport can identify one approved rules, ML, or hybrid release that produced a prediction.
3. Sport prediction rows use `updateOrCreate(['game_id' => ...])`, overwriting prior outputs and destroying revision history.
4. `prediction_feature_snapshots` combine inputs and outputs and are written after the prediction. They are not immutable pre-calculation input snapshots.
5. `model_runs` mixes serving calculations, historical reconstruction, research, shadow inference, training, and weekly automation in one table.
6. Evaluations and many downstream consumers still reference sport-specific tables and integer IDs.
7. Live prediction actions mutate pregame prediction rows rather than creating separate live revisions.
8. The central calculators are oversized and combine data access, feature construction, formula execution, explanations, persistence, and side effects.
9. Some business calculations still run while preparing API resources, so the value shown to a consumer is not necessarily a stored, reproducible output.
10. Removing legacy prediction code requires a measured migration: 162 PHP files directly reference sport-specific prediction models.

## Current production-clone coverage

The August 13 production clone contains the following canonical migration coverage:

| Sport | Canonical predictions | Matching input/output snapshots | Canonical run linked | Distinct legacy predictions evaluated |
|---|---:|---:|---:|---:|
| CBB | 2,547 | 48 | 0 | 37 |
| CFB | 99 | 99 | 0 | 0 |
| MLB | 4,881 | 4,881 | 2,430 | 4,273 |
| NBA | 505 | 259 | 0 | 246 |
| NFL | 2,696 | 2,696 | 2,383 | 2,384 |
| WCBB | 1,216 | 58 | 0 | 59 |
| WNBA | 1,419 | 1,419 | 769 | 1,369 |

Six additional MLB legacy predictions were not canonicalized because their games have conflicting provider identities. They remain quarantined correctly.

The clone also has 323 `model_runs`, 12 `model_artifacts`, 172,584 legacy feature snapshots, zero `feature_schemas`, and zero `dataset_export_manifests`. The absence of the last two registries is why every canonical prediction fails the current strict lineage report; it does not prove that every calculation result is invalid.

## Target records and responsibilities

The target should stay small. Existing canonical tables should be evolved where possible instead of creating a parallel third prediction system.

### `sport_events` — keep

Purpose: stable identity for the real-world game.

Keep the existing canonical event and provider-mapping tables. Sport-specific game tables remain detail tables during migration.

### `event_input_snapshots` — add

Purpose: immutable, point-in-time evidence of what the calculator knew.

Minimum fields:

- public ID and `sport_event_id`
- phase: `pregame` or `live`
- schema version
- captured and source-available timestamps
- source timestamp summary
- normalized feature document or private object URI
- content SHA-256
- pregame-safety status

A unique content key should prevent duplicate snapshots for the same event, phase, schema, and hash. Large documents belong in private object storage.

### `calculation_releases` — add

Purpose: the approved, immutable definition of a calculator.

Minimum fields:

- public ID, sport, phase, and calculator name
- type: `rules`, `ml`, or `hybrid`
- semantic version
- code revision and configuration hash
- required input-schema version
- status: `draft`, `approved`, `retired`, or `invalidated`
- effective and retired timestamps
- approval actor, reason, and metadata

Rules releases require code and configuration evidence. ML and hybrid releases also attach one or more promoted model artifacts through a release-component table. A prediction should not directly reference training datasets or artifacts.

### `calculation_runs` — add

Purpose: one execution of one approved release against one immutable input snapshot.

Minimum fields:

- UUID, `sport_event_id`, `event_input_snapshot_id`, and `calculation_release_id`
- trigger and phase
- idempotency key
- status: `pending`, `running`, `succeeded`, or `failed`
- started and completed timestamps
- output hash, diagnostics, and failure information

The idempotency key should cover event, snapshot hash, release, and phase.

### `predictions` and `prediction_markets` — evolve

Purpose: immutable canonical prediction revisions and their outputs.

Keep the existing canonical tables, but change their semantics from a legacy projection to the authoritative revision ledger. Add:

- `calculation_run_id`
- revision number and optional `supersedes_prediction_id`
- phase
- publication state
- output hash
- withdrawal or supersession timestamps

Remove the assumption that one legacy detail row can have only one canonical prediction. A current prediction is the latest eligible published revision, selected by a query—not a row that is repeatedly overwritten.

### `prediction_evaluations` — evolve

Purpose: evaluation of an exact immutable prediction revision.

Add a nullable canonical prediction foreign key during expansion. Dual-write it while legacy references remain. The final contract removes `prediction_table`, `prediction_id`, and the unqualified sport-specific `game_id` after every consumer uses canonical IDs.

### ML and research records — keep, clarify ownership

Keep:

- `dataset_export_manifests`
- `feature_schemas`
- `model_artifacts`
- training, evaluation, and promotion services
- shadow outputs and model feedback

Stop using `model_runs` for serving calculations. Preserve it for research, training, evaluation, and shadow lifecycle records, or rename it in a later contract migration once all serving references have moved to `calculation_runs`.

## 1. Introduce calculation releases

### Current implementation

- Rules versions are class constants in `app/Actions/Sports/AbstractPredictionGenerator.php` and sport overrides.
- Runtime configuration is read directly from `config/{sport}.php` during calculation.
- `ModelRunRecorder` hashes the current configuration and attempts to capture a source revision.
- `model_artifacts` stores artifact hashes, evaluation reports, promotion decisions, and status.
- MLB and NFL have substantial artifact, shadow, and promotion infrastructure.
- The production clone has one promoted MLB artifact, no promoted NFL artifact, and no registered artifacts for the other sports.

### Refactor required

- Add `CalculationRelease`, `CalculationReleaseComponent`, and an explicit selector.
- Register one approved rules release per current sport/phase before changing output behavior.
- Freeze the resolved configuration in the release; do not reread unversioned configuration throughout a run.
- Make ML promotion create or activate a calculation release. Artifact promotion alone must not change public serving behavior.
- Record which markets and phases a release is approved to serve.

### Deprecation candidates

- Direct use of `MODEL_VERSION`, `FEATURE_VERSION`, and `BLEND_VERSION` as proof of an approved release.
- Direct public-serving selection from `ModelArtifact::status` without a calculation release.
- Direct lineage columns on canonical predictions: `feature_schema_id`, `dataset_export_manifest_id`, `model_run_id`, and `model_artifact_id`. Their evidence belongs behind the run/release chain.

### Acceptance criteria

- Every active sport and phase has exactly one selectable approved release for a given effective time.
- Rules releases do not require fabricated training datasets or model artifacts.
- ML/hybrid releases cannot be approved without valid artifact, dataset, feature-schema, and evaluation evidence.

## 2. Separate immutable input snapshots

### Current implementation

`PredictionFeatureSnapshotRecorder` creates a `model_run`, then stores features, outputs, market context, model metadata, timing, and provenance in `prediction_feature_snapshots`. It is called after the legacy prediction has already been persisted.

The table has no canonical event foreign key and the model has no immutability guard. Its `prediction_table`, `prediction_id`, and `game_id` fields are unenforced polymorphic references.

### Refactor required

- Split feature gathering from formula execution.
- Add one input-snapshot builder contract and sport-specific implementations.
- Capture the snapshot before executing the calculator.
- Reference `sport_event_id`, not `game_table + game_id`.
- Hash a canonical serialization of normalized input values and source timestamps.
- Keep outputs out of the input snapshot.
- Add model and database protections against update/delete outside an explicit retention workflow.
- Continue exporting trustworthy historical snapshots for research.

### Reusable code

- `SnapshotProvenanceResolver`
- point-in-time safety fields and source timestamps
- sport feature gathering currently embedded in generators
- odds snapshots, market quotes, weather, injury, depth-chart, team-metric, and signal stores

### Deprecation candidates

- `PredictionFeatureSnapshotRecorder` as the serving write path.
- `SportPredictionFeatureSnapshotQuery`, once resources read canonical prediction/release summaries.
- `prediction_feature_snapshots` for new serving writes. Retain historical rows for research until exports and retention policy are complete.
- Repeated `model_metadata` JSON on sport prediction rows.

### Acceptance criteria

- Replaying a snapshot requires no query to mutable sport tables.
- `captured_at` and every material source timestamp are no later than the applicable cutoff.
- Identical normalized inputs produce the same hash.

## 3. Make canonical predictions immutable revisions

### Current implementation

- Seven legacy prediction tables are the operational source of truth.
- Shared and sport-specific generators call `updateOrCreate(['game_id' => ...])`.
- Live actions mutate `live_*` columns on the same rows.
- Grading mutates outcome and error columns on the same rows and changes `updated_at`.
- The canonical `predictions` table is populated only through `CanonicalPredictionSyncService`.
- Its unique legacy-detail key permits only one canonical row per legacy row.
- Neither `CanonicalPrediction`, `PredictionMarket`, nor the legacy prediction models are immutable.

### Refactor required

- Evolve `predictions` into an append-only revision ledger.
- Add a current-prediction query scoped by event, phase, publication state, and effective time.
- Create new markets for every revision instead of updating/deactivating markets on an old revision.
- Separate prediction outcomes from evaluations.
- Treat narratives and AI analysis as linked enrichments, not mutable columns on the prediction.
- Move compatibility linkage to a separate legacy mapping table.

### Deprecation candidates

- The unique `detail_source + detail_sport + detail_id` constraint.
- Canonical `detail_source`, `detail_sport`, and `detail_id` after mappings are externalized.
- Canonical `model_version`, `feature_version`, and `blend_version` after releases are authoritative.
- Mutating `is_primary` to represent stale markets.
- All `live_*`, grading, narrative, and large `model_metadata` columns on legacy prediction tables after consumers migrate.

### Acceptance criteria

- A material input or release change creates a new prediction ID.
- Published prediction rows and markets cannot be updated or deleted by normal application code.
- The exact pregame revision published at game start remains permanently addressable.

## 4. Add shared prediction orchestration

### Current implementation

`AbstractPredictionGenerator` handles eligibility, data access, feature construction, formula execution, persistence, snapshot recording, and narrative dispatch. NFL bypasses most of that base through a separate 6,036-line historical-Elo generator.

Approximate generator sizes:

| Component | Lines |
|---|---:|
| NFL historical/current generator | 6,036 |
| CFB generator | 2,232 |
| MLB generator | 1,267 |
| NBA generator | 987 |
| Shared base generator | 965 |
| WNBA generator | 353 |

### Refactor required

Add a shared application action such as `GeneratePredictionForEvent` with this order:

1. Resolve the canonical event and eligible phase.
2. Select the effective approved calculation release.
3. Build or reuse the immutable input snapshot.
4. Claim an idempotent calculation run.
5. Invoke a sport calculator using only the snapshot and release.
6. Validate outputs.
7. Persist the canonical prediction revision and markets atomically.
8. Mark the run succeeded with an output hash, or failed with diagnostics.
9. Evaluate publication policy and publish separately.
10. Project to legacy tables temporarily while compatibility readers remain.

The sport contract should be small:

```php
interface SportCalculator
{
    public function calculate(
        EventInputSnapshotData $snapshot,
        CalculationReleaseData $release,
    ): PredictionOutput;
}
```

### Reusable code

- Existing formulas and sport-specific context services
- shared date/season eligibility
- `PredictionSummary` and other typed read models
- narrative queueing as a post-prediction event listener
- cache invalidation as a post-commit listener

### Deprecation candidates

- Persistence and side effects inside `AbstractPredictionGenerator`.
- `ModelRunRecorder::forPrediction()` for serving execution.
- Direct narrative dispatch from calculators.
- Direct cache busting from each generate command.

### Acceptance criteria

- Calculator tests run without database access.
- Orchestration integration tests prove ordering, idempotency, failure handling, and rollback.
- Old and new outputs match from the same frozen input fixture before a sport switches readers.

## 5. Migrate WNBA, then NBA

### Why this pair goes first

WNBA has complete snapshot coverage for its canonical rows and a relatively contained generator. NBA shares the professional-basketball family but exercises more complex injury, venue, calibration, and residual-model behavior.

### WNBA refactor

- Extract Elo, team-metric, market, and output calculations from `Actions/WNBA/GeneratePrediction` into a pure calculator.
- Move database queries in `teamMetricsForGame()` and Elo resolution into the WNBA snapshot builder.
- Preserve historical reconstruction metadata but classify reconstructed snapshots explicitly.
- Adapt WNBA betting-value and signal services to canonical prediction markets.

### NBA refactor

- Extract recent form, venue splits, rest, turnovers, rebounds, injuries, and EPA feature gathering into the snapshot builder.
- Keep weighting and blending in the pure calculator.
- Represent the win-probability calibration model as an optional ML component of an approved hybrid release.
- Convert NBA live prediction updates to live snapshots and revisions.

### Removal after parity

- Direct writes by WNBA/NBA `GeneratePrediction` and `UpdateLivePrediction`.
- WNBA/NBA legacy model reads in V2 queries, scoreboard payloads, dashboard, alerts, and narratives.
- Sport-specific prediction tables only after all compatibility projections and external readers are zero.

## 6. Migrate CBB and WCBB together

### Current strengths

CBB and WCBB already share `AbstractCollegeBasketballPredictionGenerator` and common command bases. Their thin sport generators should remain thin.

### Refactor required

- Extract the shared college-basketball snapshot builder and calculator.
- Keep sport-specific eligibility, placeholder handling, configuration, and tournament concerns outside the game-prediction calculator.
- Move CBB betting-value calculation out of API preparation and into stored prediction markets or a subsequent betting-decision calculation.
- Convert both live update families into live revisions.

### Historical caveat

Only 48 CBB and 58 WCBB canonical rows have matching snapshots. Older rows must remain `legacy` or `partial`; they cannot be made fully reproducible by inventing snapshots.

### Removal after parity

- CBB/WCBB direct legacy prediction writes and reads.
- Duplicated sport resource wrappers where the V2 shared resource fully covers the contract.
- Legacy live and grading columns after canonical live revisions and evaluations are stable.

## 7. Migrate CFB and NFL together at the contract level

They share football concepts, but their calculations must remain separate implementations.

### CFB refactor

`Actions/CFB/GeneratePrediction` combines more than 2,200 lines of preseason, FPI, special-teams, schedule, coaching, transfer, market, injury, EPA, and calibration logic.

Extract:

- point-in-time CFB input builder
- preseason signal component
- context and market component
- calibration component
- pure output combiner
- explanation/reason-code presenter

The production clone has snapshots and candidate run records for all 99 CFB predictions, but no canonical prediction is linked to a run, multiple runs match each surviving row, and no evaluation records exist. Do not guess historical run identity.

### NFL refactor

`GeneratePredictionFromHistoricalElo` is the largest migration risk. Its useful boundaries already appear as methods and supporting services but are held together in one stateful class.

Extract in behavior-preserving slices:

- baseline Elo and market calculation
- EPA and efficiency features
- quarterback and line matchup features
- injury and depth-chart features
- weather, schedule, coaching, and historical-record context
- calibration and trust adjustments
- reason codes and explanation generation
- shadow inference adapter

The existing `preview($game)` versus persistent execution split is a useful seam for parity fixtures.

### ML handling

NFL shadow/challenger artifacts remain research components until an approved calculation release explicitly selects them for a market. Promotion must never silently replace the public rules release.

### Removal after parity

- CFB/NFL persistence within the large generators.
- Legacy prediction-table references in signal observations, AI analysis, and backtests after canonical mappings exist.
- Direct live-row mutation.

## 8. Migrate MLB last

### Why MLB is last

MLB combines the baseline full-game prediction, pitcher and weather context, full-game tabular shadow models, F3/F5 period models, market-aware projections, pick candidates, betting decisions, and starting-pitcher forecasts.

### Refactor required

- Extract a full-game snapshot builder covering teams, pitchers, bullpen, park, weather, injuries, odds, and source timing.
- Extract the baseline rules calculator from `Actions/MLB/GeneratePrediction`.
- Represent full-game and period ML models as release components with explicit market scopes.
- Keep period snapshots separate when their inputs/cutoffs differ from full-game inputs.
- Make daily picks consume canonical prediction markets and calculation-run evidence.
- Replace `source_table`, `game_table`, and `prediction_table` references in MLB pick and decision records with canonical FKs.
- Resolve the six provider identity conflicts before enabling canonical-only writes.

### Keep intact

- point-in-time historical reconstruction safeguards
- artifact hash verification
- chronological evaluation
- private shadowing and promotion gates
- market quote and decision settlement history

### Removal after parity

- MLB legacy prediction persistence and embedded live fields.
- transitional canonical backfill logic.
- legacy source references in pick candidates and decisions once mappings and comparison reports are clean.

## 9. Move evaluation to canonical revisions

### Current implementation

Every sport has a `GradePredictions` action using `AbstractGradePredictions`. It mutates legacy predictions, then `PredictionEvaluationRecorder` writes a row keyed by table name, numeric prediction ID, and version strings.

### Refactor required

- Introduce `EvaluatePredictionRevision` against an official canonical event result.
- Grade the exact published pregame revision and, separately, eligible live revisions.
- Store scoring metrics per market where appropriate: probability score, spread error, total error, result, CLV, and ROI.
- Preserve evaluation revisions if an official result is corrected.
- Make monitoring, backtests, and promotion reports consume canonical evaluations.
- Add a compatibility projector for legacy grading fields only while legacy readers need them.

### Deprecation candidates

- `prediction_table`, `prediction_id`, and unqualified `game_id` in `prediction_evaluations`.
- `actual_*`, `*_error`, `winner_correct`, pick-result, and `graded_at` fields on sport prediction rows.
- `RebuildPredictionEvaluationsCommand` after canonical evaluation backfill is complete.

### Acceptance criteria

- Every evaluation points to one immutable prediction revision and official event outcome.
- Grading never changes the prediction's creation or generation timestamps.
- Evaluation reports distinguish pregame, live, rules, ML, and hybrid releases.

## 10. Retire compatibility code and tables

Removal is a later contract phase, not part of initial implementation.

### Transitional services to remove

- `CanonicalPredictionSyncService`
- `CanonicalPredictionLineageResolver`
- `BackfillCanonicalPredictionsCommand`
- the current direct-link version of `CanonicalPredictionLineageReadinessService`
- user-bet legacy prediction-reference backfill after canonical references are required

Replace the readiness report with a lifecycle report that validates:

- event → input snapshot
- input snapshot → calculation run
- run → approved release
- run → prediction revision and output hash
- ML release → feature schema, dataset, artifact, and evaluation

### Legacy domain code to retire

- seven sport-specific prediction models and tables
- seven direct persistence paths in `GeneratePrediction`
- seven mutable `UpdateLivePrediction` paths
- seven legacy `GradePredictions` paths
- sport game `prediction()` relations that target legacy tables
- legacy prediction factories and table-specific fixtures

The sport calculator classes themselves are not removal targets. Their formulas move behind the shared calculator contract.

### Reader and transport code to retire or refactor

- `SportPredictionQuery` dynamic selection of legacy prediction models
- `SportPredictionFeatureSnapshotQuery`
- `LiveScoreboardPayloadService` legacy prediction model map
- legacy `AbstractPredictionController` and sport prediction controllers after V1 retirement gates pass
- legacy sport prediction resources after V2 and all external consumers use canonical read models
- dashboard, digest, alert, and game-page queries that traverse sport `prediction()` relations
- frontend assumptions that prediction identity equals sport-table integer ID or game ID

The current API surface has 334 `/api/v1` routes and 77 `/api/v2` routes. V1 removal must continue to use production usage logging and the existing API retirement policy.

### Betting and enrichment references to retire or refactor

- `user_bets.prediction_type`, `prediction_sport`, and legacy `prediction_id`
- `UserBetPredictionReference` and its resolver after `prediction_id` references the canonical revision and a market FK is available
- `bet_decisions.source_table`, `source_id`, `game_table`, `game_id`, `prediction_table`, and legacy `prediction_id`
- duplicated `feature_snapshot` and `market_snapshot` JSON once immutable source records are referenced and hashes are preserved
- `sports_ai_prediction_analyses.game_id` and ambiguous `prediction_id`
- narrative columns on every sport prediction table
- read-time calls to sport `CalculateBettingValue` actions in `PredictionResourcePreparer`

User bets should ultimately reference the exact canonical prediction revision and prediction market visible when the bet was placed. Bet decisions should reference the canonical event, prediction market, input snapshot, calculation run, and any quote used.

## Deprecation register

| ID | Candidate | Replacement | Deprecate during | Remove only after |
|---|---|---|---|---|
| D01 | Direct version constants as release identity | `calculation_releases` | Step 1 | Every active release is registered and selectable |
| D02 | Serving rows in `model_runs` | `calculation_runs` | Steps 1–4 | New predictions no longer create serving `model_runs` |
| D03 | `prediction_feature_snapshots` serving writes | `event_input_snapshots` | Steps 2–4 | Research exports and all shadow consumers migrate |
| D04 | Legacy prediction `updateOrCreate` | Canonical revision append | Step 3 onward | Per-sport parity and rollback window pass |
| D05 | Canonical legacy-detail columns/unique key | Legacy mapping table | Step 3 | All legacy rows mapped and no new legacy-first writes |
| D06 | Direct lineage columns on `predictions` | Run → release → optional ML evidence | Steps 1–4 | Lifecycle report replaces direct-link gate |
| D07 | Generator persistence and side effects | Shared orchestration | Step 4 onward | Each sport adapter passes parity fixtures |
| D08 | Mutable `live_*` columns/actions | Live snapshots and revisions | Per-sport migration | Live API parity and retention checks pass |
| D09 | Legacy evaluation keys and grading columns | Canonical evaluation FK | Step 9 | Evaluation comparison and result-correction tests pass |
| D10 | `CanonicalPredictionSyncService` and backfill | Native canonical writes | Step 10 | Zero legacy-first writes for the observation window |
| D11 | V2 legacy prediction queries | Canonical prediction read repository | Per-sport migration | V2 contract and query-count tests pass |
| D12 | V1 prediction controllers/resources | V2 canonical contracts | Step 10 | Production usage is zero for the agreed window |
| D13 | User-bet polymorphic prediction reference | Canonical revision + market FKs | Steps 3–9 | All bets mapped or explicitly classified |
| D14 | Bet-decision table-name references | Canonical event/run/snapshot/market FKs | Steps 7–9 | Settlement and feedback parity pass |
| D15 | AI analysis and narrative legacy IDs/columns | Canonical revision enrichments + `ai_generations` | Steps 4–9 | UI, digest, and alert readers migrate |
| D16 | Read-time betting-value calculations | Stored markets/decisions | Per-sport migration | Output parity and freshness policy pass |
| D17 | Seven legacy prediction tables/models | Canonical predictions and read models | Final contract | All preceding D-items and backup/archive gates pass |

## Code to preserve

The following are not cleanup targets:

- canonical sport events and provider mappings
- sport-specific game detail tables during this program
- existing sport formulas and their validated constants
- point-in-time source and safety checks
- odds snapshots and normalized market quotes
- dataset manifests, feature schemas, model artifacts, evaluation reports, and artifact hash verification
- chronological backtesting and shadow deployment logic
- bet settlements, ROI, and CLV history
- typed application read models and API V2 contract tests
- object-storage provenance and provider source manifests

## Tests and architectural guardrails to add

### Lifecycle architecture tests

- Calculators cannot use Eloquent, `DB`, `Schema`, service location, queues, cache, or HTTP clients.
- Published predictions and their markets cannot be updated or deleted.
- Input snapshots and approved releases are immutable.
- Transport resources cannot run business calculations.
- New code cannot add table-name polymorphic references.
- New prediction writers must use the shared orchestration action.

### Per-sport parity suites

For every sport, freeze representative input snapshots covering:

- normal scheduled game
- missing or stale inputs
- neutral/home venue behavior
- injury and availability effects
- market present and absent
- early-season fallback
- historical reconstruction
- live phase where supported

Run the old generator in preview mode and the new calculator against the same fixture. Compare every published market and reason code with exact or explicitly approved tolerances.

### Operational gates

- zero calculation runs without event, snapshot, and approved release
- zero published predictions without a successful run and matching output hash
- no duplicate run for the idempotency key
- no source timestamp after the prediction cutoff
- canonical/legacy comparison during dual-write
- per-sport reader traffic and legacy-write counters
- rollback test that switches readers back without deleting canonical data

## Implementation sequence and dependencies

```text
1. Calculation releases
      ↓
2. Immutable input snapshots
      ↓
3. Canonical revision schema
      ↓
4. Shared orchestration and dual-write
      ↓
5. WNBA → NBA
      ↓
6. CBB → WCBB
      ↓
7. CFB → NFL
      ↓
8. MLB
      ↓
9. Canonical evaluation and downstream references
      ↓
10. Contract migrations and removals
```

Within each sport migration:

1. Extract input gathering without changing formulas.
2. Add frozen parity fixtures from real, sanitized snapshots.
3. Extract the pure calculator.
4. Enable canonical dual-write behind a sport feature flag.
5. Compare old and canonical outputs in production without changing readers.
6. Switch internal readers, then V2 readers.
7. Observe errors, output drift, and legacy usage for a complete active-sport cycle.
8. Disable legacy writes.
9. Remove compatibility code only in a later contract release.

## Final definition of done

- Every sport uses the same event → snapshot → release/run → prediction → evaluation lifecycle.
- Sport calculators differ only in their feature and formula implementations.
- Every new published prediction is reproducible from immutable inputs and an approved release.
- ML predictions additionally trace to training dataset, feature schema, artifact, and evaluation evidence.
- Rules predictions trace to immutable code and configuration without fabricated ML records.
- Pregame and live outputs are separate immutable revisions.
- User bets, decisions, narratives, alerts, and evaluations reference exact canonical revisions.
- API V2 and web read from the same canonical application query and typed read models.
- No business calculations execute in controllers or resources.
- Legacy code and tables are removed only after dual-write parity, zero usage, rollback, and archive gates pass.
