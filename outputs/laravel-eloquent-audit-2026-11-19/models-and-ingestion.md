# Eloquent model, action, and service audit

Baseline audit evidence. For implemented changes and current completion status, use the [README checklist](README.md).

Read-only source review, November 19, 2026. No application edits, jobs, provider calls, or database operations. Priorities indicate cleanup order, not confirmed production incidents. Findings below were inspected in source; runtime costs were not measured. Root AGENTS.md and engineering conventions apply. C identifiers in the README are the completion tracker. This is broad pattern coverage, not proof that every call site in the repository is clean.

## 1. P1: Delete-and-rebuild ingestion is not atomic

- `app/Actions/ESPN/AbstractBasketballSyncPlayerStats.php:22` deletes all game stats before parsing/resolving every athlete and writing them one at a time at line 68. No transaction in this action. Applies to subclass implementations `app/Actions/ESPN/NBA/SyncPlayerStats.php:10`, `app/Actions/ESPN/CBB/SyncPlayerStats.php:10`, `app/Actions/ESPN/WCBB/SyncPlayerStats.php:10`, `app/Actions/ESPN/WNBA/SyncPlayerStats.php:10`.
- `app/Actions/ESPN/AbstractFootballSyncPlayerStats.php:22` similarly deletes first; per-athlete lookup at 53, stat lookup at 59, create at 65, update at 77. Applies to `app/Actions/ESPN/NFL/SyncPlayerStats.php:10`. CFB overrides execute with its own transaction and must not be grouped into the same atomicity defect.
- `app/Actions/ESPN/MLB/SyncPlayerStats.php:24` deletes before player lookup and section validation; writes at 74, no transaction in action.
- `app/Actions/ESPN/AbstractSyncPlays.php:46` deletes all plays before DTO parsing and per-play create at 64, no transaction in action. Inherited execute applies to NFL, NBA, CBB, WCBB, WNBA, MLB `SyncPlays` classes. CFB overrides execute and is excluded.

Failure halfway leaves a partial corpus; incomplete-but-present arrays can erase existing rows. Stage/validate a complete replacement first, then transact reconciliation. Consider locking game consistently for competing syncs. The normal shared `AbstractSyncGameDetails::execute` caller also lacks a transaction around these replacements. Verify any alternate entry points before implementation; stage validation and atomic replacement within the action so callers cannot omit the guarantee. Regression: invalid/missing player and injected middle-write failure preserve prior complete rows. Preserve source completeness semantics and all observer side effects.

## 2. P2: Ingestion repeats player/stat queries and unnecessary existence checks

- Same stat import locations above: basketball resolves player via `updateOrCreate` at `AbstractBasketballSyncPlayerStats.php:121` per athlete and then `updateOrCreate` for stats despite deleting existing stats.
- `app/Actions/ESPN/MLB/SyncPlayerStats.php:54` repeats player lookup per athlete per stat section; line 74 uses updateOrCreate after delete.
- `app/Actions/ESPN/AbstractFootballSyncPlayerStats.php:53` repeatedly loads player and stat per category; same player may appear in multiple categories.
- `app/Actions/ESPN/CFB/SyncPlayerStats.php:46` looks up player per category after identity resolver has already resolved the boxscore; line 56 upserts each assembled player row individually. This action does have transaction and completeness validation.
- `app/Actions/ESPN/AbstractSyncPlays.php:133` queries possession team per play. Preload team IDs per payload or memoize per execute.
- `app/Actions/ESPN/AbstractSyncGames.php:115-116` does two team lookups per game through line 92; line 148 queries existing game per item and line 169 fresh-loads after update.

Batch identity reads (`whereIn()->get()->keyBy()`), combine sections per natural key before writing, and choose insert/upsert only where events/casts are not needed. Do not blindly replace lifecycle-aware game saves or player creation with upsert. Per-game scopes are bounded, so query multiplication is verified but material production impact is unmeasured.

## 3. P2: Per-fact Player::find inside injury calculation

`app/Actions/NFL/GeneratePredictionFromHistoricalElo.php:2767`: each accepted research-availability fact creates a temporary injury and calls `Player::find`. Fetch player IDs once per preview, key them, pass into both team calculations. Keep point-in-time selection and source precedence unchanged. Regression should assert equal outputs plus bounded player queries for multiple facts.

## 4. P2: Canonical prediction overwrites a differently declared relationship

`app/Services/Api/V2/SportGameQuery.php:162` puts CanonicalPrediction under `prediction`. `app/Models/NFL/Game.php:132` declares `prediction()` as HasOne to NFL Prediction; MLB declaration at `app/Models/MLB/Game.php:281` likewise sport-specific. Query relation profiles also load legacy prediction before canonical attachment. Use separate declared canonical relation or explicit selected-forecast data, avoid both redundant old load and mutable relation substitution. This is a semantic/API hazard; no incorrect betting result established.

## 5. P2: Betting calculation temporarily swaps model relationship

`app/Services/Predictions/PredictionResourcePreparer.php:177-191` temporarily replaces game prediction and restores in finally. Restoration is careful, but calculations have hidden dependence on mutable relationship state. Accept prediction explicitly in calculator alongside game. Keep existing numerical behavior and pricing/permissions. Distinct from legitimate assigning of the same loaded relation model type.

## 6. P2: Repeated whole-team evidence loads within each CFB prop

`app/Services/CFB/CfbPlayerEvidenceService.php:83` calls history, line 102 injuries; line 108 calls quarterback which re-fetches history (45) and injuries (51). `history` hydrates whole team across two seasons with game/player relations and PHP-sorts at line 26. `forProp` repeats this work for each prop. Introduce immutable team/game/as-of evidence context, load once for a batch, and pass to quarterback/prop calculations. Cache lifetime must be request/batch-scoped and include cutoff. Preserve teammate participant checks and full-history fields, not just selected player rows. Verify equal windows/holds and bounded query counts.

## 7. P2: Entire Elo history loaded to choose one latest row

`app/Services/CFB/Predictions/CfbPointInTimeElo.php:71`: latestObserved hydrates eligible ratings and related games, sorts collection, then first. Push ordering by related game date plus game_id into join/subquery and `first`, retaining every created/updated/rebuilt/cutoff constraint. Query-builder/join is appropriate here. Sample is season-bounded but only one row needed.

## 8. P2: Boolean cached as an Eloquent relationship

`app/Services/Predictions/LivePredictionStore.php:107` stores baselineValidity boolean via setRelation, read at 61-62. Model relation serialization expects relation-shaped values; a boolean is transient computation, not relationship. Move to explicit validity context/typed cache. `livePredictionSnapshot` at lines 77/110 is a real model-shaped value, but no declared relationship on NFL Game; define a constrained relationship if practical or keep an explicitly named store/cache rather than using undocumented relation namespace. CFB already uses declared latestLiveSnapshot. Avoid replacing the existing bounded snapshot batch query with N+1 lookups.

## 9. P2: Non-persistent inputs attached as model attributes

- `app/Services/NFL/Research/ResearchPipeline.php:40`: research_availability; line 68 research_candidate.
- `app/Services/Api/V2/NflGamePageQuery.php:21`: matchup_context.
- `app/Services/Settings/CfbdTeamMappingIndexDataService.php:120-121`: suggested name and score (tracked under C25, not C21).

These become dirty Eloquent attributes even though not schema fields. Use explicit input/presentation context passed to preview/resource, not model storage. No accidental persistence confirmed. Research is particularly important because model objects travel through many collaborating services. Keep numerical preview non-persisting.

## 10. P3: Historical read models masquerade as mutable Eloquent predictions

`app/Services/NFL/NflCalibrationHistory.php:71-76` builds unsaved NFL Prediction models from immutable feature snapshot outputs and adds calibration_inputs attribute. At line 93 a clone is mutated for calibration stage. Existing code explicitly avoids using mutable rows as evidence, which is good. A typed historical-observation DTO would make this guarantee structural and prevent query/persistence capabilities from escaping. This is a larger contract refactor, lower urgency; do not replace snapshot evidence with actual mutable prediction models.

## 11. P3: Query-builder methods named like Eloquent relationships

`predictions(): Builder` occurs at:
- `app/Models/NFL/Team.php:69`
- `app/Models/NBA/Team.php:71`
- `app/Models/CBB/Team.php:90`
- `app/Models/CFB/Team.php:115`
- `app/Models/WCBB/Team.php:76`
- `app/Models/WNBA/Team.php:82`

These are whereHas queries for home OR away game, not Relation objects; cannot eager-load with with('predictions') or access as relation property. Rename to predictionQuery/queryPredictions or move to dedicated query service/scope. Do not force OR-side association into hasManyThrough. Repository search found no application ->predictions() calls using these Team methods, so removal may be appropriate after public compatibility review. SportEvent::predictions is a genuine HasMany and should remain.

## 12. P3: Hidden relation queries in NFL prediction convenience accessors

`app/Models/NFL/Prediction.php:85-92` home_team/away_team accessors traverse game and teams. Can lazy-load unexpectedly when called on collections. Prefer explicit `game.homeTeam`, `game.awayTeam` load contract/resource fields; keep convenience methods only with enforced eager loading and query-count coverage. No observed N+1 endpoint from this source alone.

## 13. P3: Re-fetching loaded probable pitcher identities

`app/Services/Sports/GameMatchupContextService.php:603,606` queries MLB Player separately by ESPN ID despite page/detail call paths hydrating probable pitcher relationships. Reuse loaded relationships first and batch fallback identities if absent; preserve probable versus actual/projected semantics. This is redundant lookup in the page path, not a proven N+1 across a collection. `GameMatchupContextService.php:27-28` fallback homeTeam()->first does not populate relation; loadMissing on supported model relationships would avoid repeated lookups when subsequent components reuse same instance.

## Deliberate patterns not flagged

- SQL/report aggregation and joins are valid Laravel; no demand to convert all SQL into relationships.
- `AbstractSyncPlayerInjuries` already batches player mappings and existing injury reads; do not regress it to per-row queries.
- Model casts are already widespread. Decimal casts intentionally preserve decimal strings and must not be globally changed to float merely to remove casts in resources.
- Old-style getFooAttribute accessors are supported, so naming style alone is not a cleanup defect.
- CFB point-in-time filtering, safety gates, immutable snapshots, and lifecycle observers require preserving semantics; plain PHP used to calculate domain math is appropriate.

## 14. P3: Export-only attribute mutation

`app/Console/Commands/MLB/ExportTrainingDataCommand.php:165` attaches computed prediction_horizon to feature snapshot model to carry it through export sorting and mapping. Prefer an explicit export row/context alongside source snapshot. It is local export-only state; no accidental save shown. Lower priority than shared runtime model mutation.

## 15. P3: Manual construction bypasses job dependency injection

`app/Jobs/ESPN/NFL/FetchGameDetails.php:35-39` manually constructs the service and sync actions in handle. First add concrete sport constructors or contextual bindings for generic parent collaborators; then Laravel method injection can resolve the graph and honor configured bindings. Injecting the existing action unchanged is insufficient. This is framework integration cleanup, not Eloquent correctness. Preserve this job's uniqueness and existing action semantics.

Additional verification for #1: `AbstractSyncGameDetails.php:42-46` invokes play/stats actions without a surrounding transaction; `AbstractStandardSyncGameDetails` is empty. NFL's overriding execute calls parent directly. Typical FetchGameDetails job path thus has no parent atomic boundary either.

Additional sibling coverage for #15: manual graph construction also occurs at `app/Jobs/ESPN/NBA/FetchGameDetails.php:35-39`, `app/Jobs/ESPN/CBB/FetchGameDetails.php:35-39`, `app/Jobs/ESPN/CFB/FetchGameDetails.php:35-39`, `app/Jobs/ESPN/WCBB/FetchGameDetails.php:35-39`, `app/Jobs/ESPN/WNBA/FetchGameDetails.php:35-39`, `app/Jobs/ESPN/MLB/FetchGameDetails.php:35-39`. Important: `AbstractSyncGameDetails` currently types collaborators as generic object and BaseEspnService, so simply injecting SyncGameDetails into handle will not auto-resolve correctly. Add concrete sport constructors or scoped/contextual container bindings before switching jobs to handle injection. Treat generic-object collaborator typing as part of same cleanup.
