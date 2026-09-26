# CFB prediction and operations implementation plan

Drafted September 25, 2026. Status: researched implementation proposal; no production changes made by this planning task.

## Decision and scope

Deliver two independently useful releases:

1. **Reliable production data:** make the successful September 25 repair repeatable and automatic, recover per game, preserve evidence, and remove slate-wide failures caused by individual missing markets.
2. **A tested scoring challenger:** predict a joint distribution of both teams’ scores from opponent-adjusted performance and possessions. Keep the released FPI model as the production benchmark until the challenger earns promotion.

Extend the existing Laravel application, canonical prediction lifecycle, model artifacts, historical team-total model, signal catalog, and grading services. Use offline training with versioned artifacts; production requests must not train models or download whole seasons. This plan does not promise profitability or assign arbitrary point bonuses to trends.

Team points, spreads, totals, and win probabilities are in scope. Individual player-prop modeling is a later consumer of the repaired player data; a team scoring model alone does not establish individual prop probabilities. Live prediction changes are limited initially to shared data correctness and a reusable overtime rules component.

## 1. Findings that determine the work

| Verified finding | Evidence in this repository / repair | Implementation consequence |
|---|---|---|
| Existing rows can be incomplete. | Indiana–WKU stored 39 yards instead of 513; Northwestern–Colorado stored 171 instead of 423. The scheduled details sweep selects missing records rather than proving completeness. | Store and act on explicit quality results; row presence is insufficient. |
| Unknown athletes are skipped. | `AbstractFootballSyncPlayerStats` looks up players and continues when absent. Repair restored 5,132 source-identified players. | Resolve identities before importing stats; preserve team-attributed entries separately. |
| Imports can replace good data with partial data. | Shared player/team/play actions delete existing rows before inserting a response; play import accepts an array without establishing pagination completeness. | Fetch and validate a candidate first, then commit a complete revision transactionally. |
| The pipeline does not establish historical completeness first. | `RunPregamePipelineCommand` refreshes ratings, rosters and injuries, then calculates metrics. | Insert historical verification/repair and play processing before dependent metrics. |
| One market failure stops the slate. | Pipeline returns on nonzero `cfb:sync-odds`; the repair run matched 43/95 actionable games. | Separate forecast readiness from per-market price readiness. Audit what the unmatched games actually are. |
| Closing quote selection can mix books. | `CfbStoredPregameQuote::latest()` filters game, market, side and time, but not bookmaker or participant. | Resolve closing quotes using the complete market identity. |
| EPA needs a modeling review before becoming a central feature. | `TrueEpaCalculator` uses a same-game realized-next-score fallback and zero for otherwise unseen states; next eligible play can cross a possession/half boundary. | Version a past-trained state model and explicit terminal transitions. This finding is not proof that all current pregame predictions leak their target outcome. |
| Drive context is not in the normalized play contract. | `CFB\Play` and `FootballPlayData` hold basic state, but lack explicit drive ID, before/after state and play classification provenance. | Establish drives and state transitions before fitting possession scoring. |
| Important infrastructure already exists. | `HistoricalTeamTotalProjector`, `ResultRatingModel`, 200 football signals, calibration services, immutable snapshots, releases and model artifacts. | Extend/reuse these rather than introducing parallel prediction systems. |
| Team-total markets need a contract change. | `PredictionMarket::MARKET_TYPES` currently lists moneyline, spread and total. | Add team totals deliberately through storage, APIs, grading and UI; do not hide a new market in a spread field. |

The September 25 audit verified 311 completed games, 622 team box scores, 22,743 player-game records, 55,133 processed plays, and 138 recalculated FBS team metrics. These are current-season repair results, not proof of multi-season training coverage or complete optional injury/weather/pressure fields. Source evidence: `outputs/cfb-production-stats-repair-2026-09-25.json`.

## 2. Research conclusions and design choices

**Opponent adjustment:** estimate performance relative to the defenses/offenses faced and the state of the play. CFBD documents this distinction, but its CORE methodology is proprietary and excludes FCS games, overtime and special teams. Its historical values are retrospective. Use it as a separately labeled comparator when available; do not describe our implementation as CORE or treat retrospectively published ratings as pregame observations. [CFBD CORE](https://api.collegefootballdata.com/core-ratings)

**Possessions:** ingest provider drive identifiers where available and verify reconstructed boundaries against play order and score changes. CFBD exposes drive start/end state, offense, defense and results; endpoint entitlement and historical coverage must be checked before making it a dependency. ESPN remains the existing primary adapter. [CFBD drives API](https://api.collegefootballdata.com/api/drives)

**Validation:** split by complete kickoff weeks and seasons, not random play rows. Fit transformations, ratings, shrinkage, signal coefficients and calibration entirely within each training period. Later games must never train earlier predictions. Time-series validation documentation supports chronological splitting; a custom football-week splitter is needed because games are unevenly spaced and clustered. [TimeSeriesSplit](https://scikit-learn.org/stable/modules/generated/sklearn.model_selection.TimeSeriesSplit.html)

**Probability evaluation:** measure calibration with reliability curves and uncertainty intervals alongside Brier/log loss; Brier alone does not isolate calibration. Use squared error for expected-score point forecasts and MAE as a secondary measure; evaluate full score distributions with proper distribution scores. Energy score supports joint-score evaluation; CRPS supports the one-dimensional margin and total distributions. [Calibration](https://scikit-learn.org/stable/modules/calibration.html), [scoring rules](https://scikit-learn.org/stable/modules/model_evaluation.html), [Gneiting and Raftery: proper scoring rules](https://sites.stat.washington.edu/people/raftery/Research/PDF/Gneiting2007jasa.pdf)

**Rules:** version overtime and clock-era behavior by season. Share deterministic rule transitions with the existing overtime module, while keeping its empirical live-history estimator distinct from the pregame simulator. Verify the applicable NCAA rulebook before release; third-overtime alternating conversion attempts must not be modeled as ordinary drives. [NCAA rules](https://www.ncaa.org/championships/playing-rules/football-playing-rules/), [NCAA approved changes](https://www.ncaa.org/news/media-center-changes-to-injury-timeouts-approved-in-football/)

All numerical operating limits and promotion targets below are proposed engineering policies, not externally established accuracy guarantees. Register them before inspecting the final test results.

## 3. Data contracts and storage

Reuse `ProviderSourceFile` / `ProviderImportManifest` for immutable raw responses, `DatasetExportManifest` for exports, `ModelRun` / `ModelArtifact` for training, and `EventInputSnapshot` / `CalculationRun` for predictions. Confirm their deployed migration fields before extending them.

Introduce only these dedicated tables initially:

| Proposed table | Minimum fields and identity |
|---|---|
| `cfb_game_data_checks` | game ID, source/import manifest ID, source hash, validator version, component states, structured discrepancies, checked timestamp, accepted revision. Unique game + source hash + validator version. |
| `cfb_pipeline_steps` | run ID, game ID (nullable for a shared artifact stage), stage, input hash, implementation version, state, attempts, lease/heartbeat, started/completed timestamps, output reference, error code. Unique game/shared-scope + stage + input hash + implementation version. |
| `cfb_drives` | game ID, accepted source revision, stable provider/derived drive ID, offense/defense, start/end play IDs and states, duration, outcome, offensive/defensive/special-team points, derivation version, quality. Unique revision + drive ID. |

Add normalized play fields for provider drive ID, accepted source revision, before/after possession and score state, clock seconds, normalized play family, no-play/try/kneel/spike flags, and rule/normalization versions. Preserve the original provider payload in object storage. A missing field stays null with a reason.

Keep source-attributed **Team** statistics in a structured team-stat payload. They are not fabricated athletes. For example, the repaired source credited TCU one rush for −31 yards and UNLV one incomplete pass to Team. Reconciliation must include those buckets rather than demanding that individual-only totals equal official totals.

Store adjusted ratings and diagnostics in versioned artifact payloads, with current summaries attached to existing team metrics. Freeze the exact artifact/reference and values used in every forecast; a mutable metric row is not historical evidence.

Each source has separate `event_time`, `source_updated_at` when supplied, `retrieved_at`, and `accepted_at`. Never manufacture an old availability timestamp when backfilling a corrected historical record. Separate original observed snapshots from retrospective reconstructions in datasets and reports.

## 4. Implementation backlog and acceptance tests

### PR 1 — Durable completeness and safe imports

**Existing targets:** `app/Actions/ESPN/CFB/SyncGameDetails.php`, `SyncPlayerStats.php`, `SyncTeamStats.php`, `SyncPlays.php`; shared football actions through CFB-specific extension hooks; `app/Console/Commands/ESPN/CFB/SyncGameDetailsCommand.php`.

**Add:** `CfbGameDataValidator`, `CfbPlayerIdentityResolver`, data-check migration/model, and proposed `cfb:audit-game-data` / `cfb:repair-game-data` commands.

Implementation:

- Archive/fetch the full candidate payload, including all play pages, before changing accepted rows. Network calls stay outside database transactions.
- Resolve positive ESPN athlete IDs with source names and a team belonging to that game. Use stable IDs, not fuzzy names; do not overwrite an existing player's present team based on old game participation. Record that participation on the game stat.
- Stage the complete game revision; atomically commit accepted team/player/play records. Quarantine empty or truncated responses; retain the last good revision. Preserve component provenance if the provider returns components at different times.
- Validate two distinct expected teams, field presence, final score reconciliation, official team-attributed stat buckets, duplicate play IDs, pagination counts where supplied, and terminal game state. Reconcile only fields with compatible provider definitions; distinguish net passing from gross passing/sacks.
- Mark each component `complete`, `partial`, `unavailable`, or `invalid`; attach exact discrepancies. A low play count is a warning to investigate, never a universal “under 100 = bad” rule.
- Stable provider IDs preserve lineage across corrections. If a provider changes an ID, create an explicit supersession mapping rather than silently orphaning frozen references.
- Revalidate changed payloads and recently final games even if all three kinds of rows already exist. Proposed final-game retries: approximately +15 minutes, +2 hours and next morning, then bounded retries for unresolved games. Use source hashes to avoid redundant writes.

**Must-pass fixtures:** partial Indiana 39→513; Northwestern 171→423; missing starter and entire missing roster; duplicate names/different IDs; negative Team IDs; transient empty response; two-page plays; valid short/called game; legitimate downward statistical correction; source ID change; retry after transaction failure. A second identical import creates no duplicate rows or downstream jobs. NFL importer tests must remain green if shared hooks change.

**Done:** the documented production repair cases are reproducible in fixtures and subsequent scheduled imports cannot regress their accepted records to an incomplete payload. This release can ship before the new scoring model.

### PR 2 — Per-game pipeline, recovery, and market identity

**Existing targets:** `RunPregamePipelineCommand`, `routes/console.php`, `SyncOddsCommand`, `CfbStoredPregameQuote`, `CfbSpreadAssessment`, canonical generation/evaluation and frozen-decision reports.

**Add:** stage ledger migration/model and jobs that adapt existing actions; no second scheduler framework.

Dependency order:

`collect history → validate/repair → normalize plays/drives → EPA/ratings → metrics → freeze forecast inputs → predict → price available markets → publish permitted outputs → grade after verified final`

Rosters, injuries, weather and odds refresh into timestamped evidence on separate branches. Only dependencies required by a specific model/market block that output. Shared ratings refresh once per accepted history version; games reference the result. A changed completed game invalidates affected team metrics and, for opponent-adjusted fits, the connected rating artifact and dependent upcoming forecasts.

- Preserve the existing public pipeline command; add `--game`, `--resume`, and `--dry-run` with tested semantics. Existing periodic jobs enqueue/advance this workflow rather than racing independent rebuilds.
- Route bulk imports/training to the existing `sync` worker capacity; keep live score work separate. Begin with one repair worker until measured memory supports more. Use bounded processes, leases, checkpoints and idempotency keys; retry an interrupted game without restarting the season.
- A command returning zero is not sufficient: the stage must record its data-quality/output evidence. Emit distinct slate results for complete, partial coverage, and infrastructure failure.
- Missing odds means `forecast_ready / market_unpriced`. Unreliable optional injury evidence means a disclosed scenario/uncertainty state, not “healthy.” Missing indispensable score/team identity means `forecast_unavailable`, with the exact missing input. A bad game does not suppress healthy games.
- Resolve quotes by game, bookmaker, market, period, participant and side; include contract attributes such as overtime inclusion when available. Preserve quote ID and line/price together.
- Closing line is the **last stored eligible pregame quote**, ordered by recorded creation time and ID within that identity. Both captured and stored timestamps must precede actual kickoff; missing actual kickoff uses the recorded schedule with an explicit qualification. Late-ingested historical or live quotes cannot become closing lines. For a ladder, preserve contract/alternate-line identity to avoid mixing alternate totals.
- Missing forecast freshness keeps the last valid revision visible with its timestamp; it must not look newly updated. Successful repair automatically retries the affected upcoming forecasts before kickoff.

**Must-pass tests:** one missing book/event does not stop other games; no quote does not prevent a valid score forecast; overlapping runs/lease expiry; crash between commit and job dispatch; changed payload invalidates dependent hashes; kickoff during processing prevents pregame publication; retry produces one revision per unique input; two books/two team totals never exchange closing lines; post-kickoff quote rejected.

**Operational acceptance:** replay all 311 repaired games without duplicates, silent failures, lost accepted rows or full-season restart. Proposed service objectives: 99% of scheduled games receive a valid forecast or explicit reason by T−60 minutes; accepted late inputs produce a revised forecast within 10 minutes when capacity permits. Measure and display misses; never meet the objective by labeling stale forecasts fresh. Audit all 52 unmatched games from the observed 43/95 odds run by unsupported coverage, mapping, timing, or provider failure.

### PR 3 — Normalized drives and trustworthy play value

**Existing targets:** `FootballPlayData`, CFB play model, CFB sync adapters, `PlayEpaDataService`, `TrueEpaCalculator`, `CalculatePlayEpaCommand`, `Services/Epa/StateBaselineService`.

**Add:** CFB-specific normalization hooks, `CfbDriveBuilder`, versioned EPA artifact reader; proposed `cfb:build-drives` and offline `cfb:train-epa-baseline` contracts.

Use explicit pre/post state, possession boundaries and half endings. Separate touchdowns, tries, punts, return scores, safeties, turnovers, no-plays and penalties. A quarter boundary does not itself end a drive; a half boundary does. Distinguish possession owner from scoring team. Reconcile total team points including defense and special teams.

Fit expected points from older games using a documented next-score-within-half target and offense-relative signs. Use a regularized state model with down, distance, field position and time; include only reliably collected context. Evaluate by later weeks. Replace the same-game state-value fallback with a versioned past-trained fallback/interpolation plus uncertainty, or mark the value unavailable. Do not set an unseen state's expected points to zero by default. Realized scoring is a historical training label, not an inference input for the state-value function.

Compute `EPA = offensive score change + signed EP(after) − EP(before)` with explicit terminal and turnover cases. Retain legacy EPA/version for comparison until acceptance. Export/exclude garbage-time states using pre-play information only; keep all regulation states available for score simulation.

**Must-pass tests:** identical pre-play states use the same EP artifact regardless of later outcomes; changing future target-game plays cannot change its earlier state-value estimate; pick-six; punt return TD; safety; halftime field goal; score on first recorded play; tries/no-plays; possession changes; missing source page; corrected ordering. One drive cannot be split just because the period changed. Every included final score reconciles or is explicitly quarantined.

### PR 4 — Historical evidence export and baseline evaluation

**Reuse:** `HistoricalTeamTotalProjector`, `ResultRatingModel`, export manifests, original event snapshots, historical signal builder and model artifacts.

Proposed command: `cfb:export-scoring-training --from-season=... --to-season=... --as-of=...` producing hashed, versioned data and a coverage report. Require explicit dates; do not silently use today's revised ratings in an old fold.

Inventory available seasons first. Candidate research window: 2016–2025, subject to measured coverage; treat 2020 and clock-rule eras separately. Reserve the latest complete eligible season untouched for final testing, with earlier expanding-week folds for selection. Preserve the repaired 2026 records for research with their actual retrieval dates; original 2026 frozen forecasts remain the source for prospective claims. If archived weekly FPI is absent, use a labeled available prior or omit that comparator; never substitute final-season FPI as if it were pregame.

Produce: current released-model replay on eligible snapshots, historical team-total baseline, FPI-only baseline where valid, and an Elo/result-rating baseline. The released model does not supply a full joint score distribution: after PR 6, construct a separately labeled distribution benchmark using the same scoring framework driven only by available FPI/prior inputs, fitted on training data. Do not claim this benchmark distribution was historically published by the released model. Report excluded games and reasons. Forecast at fixed lead times such as T−24h and T−60m; compare all models on the same games and information cutoffs. Keep repeated revisions of one game together and count one observation per chosen horizon.

**Must-pass tests:** target and later outcomes excluded; revised source availability respected; features/scalers fitted inside folds; no game crosses train/test through alternate markets; as-of replay deterministic; historical/reconstructed evidence never reported as original live performance.

**Done:** a manifest identifies actual training/validation/test counts, field coverage, timestamp quality, provider usage and artifact sizes. Insufficient history delays model promotion, not the PR 1–2 operational fixes.

### PR 5 — Opponent-adjusted efficiency challenger

**Add under `Services/CFB/Ratings`:** adjusted-efficiency artifact schema/reader. Extend existing team metric and snapshot payloads. Start offline training in a bounded `ml/cfb` package, using the repository's existing NFL/MLB training-artifact pattern; pin dependencies and export simple JSON coefficients/transforms for PHP inference.

Initial fit: regularized offense and defense effects separately for dropbacks and rushes, controlling for pre-play state and opponent. For a continuous play-value response:

`expected value = league/state effect + offense(team) + defense_allowance(opponent) + measured context`

Use explicit sign conventions, centering/identifiability constraints and game-clustered evaluation. Sacks belong to dropbacks for modeling even when official NCAA rushing accounting differs. Add success rate, explosive rate and finishing-drive features as tested groups; do not sum correlated metrics with invented point weights.

Partial pooling carries prior-season information forward. Learn shrinkage and recency settings inside chronological training folds; report effective information, not only a six-game cutoff. Talent, returning production and quarterback turnover can explain prior uncertainty only when their timestamps/coverage are usable. FPI is a net strength estimate; do not invent separate offensive and defensive FPI values.

Fit local-only, FPI-only, and learned combinations on identical folds. Learn any blend on out-of-fold predictions; do not add Elo and FPI as independent bonuses. Early-season and later-season weighting must be evidence-driven.

FCS opponents: use season-specific affiliation and identifiable opponent histories. Estimate a partially pooled tier effect only from connected, observed interdivision results. Weakly connected teams receive wider uncertainty; absent histories use the existing labeled independent result model/fallback, never an invented average opponent. Evaluate FBS–FBS and FBS–FCS separately. An external FBS-only metric cannot supply FCS evidence.

**Acceptance:** synthetic tests recover known offense/defense effects, opponent identity changes the adjustment, sparse samples shrink smoothly, and removing prior-season evidence is surfaced. On chronological holdouts, document whether local performance adds value beyond FPI; if it does not, retain the benchmark and diagnose rather than force a nonzero weight.

### PR 6 — Possession scoring and coherent market outputs

**Reuse/extend:** historical team-total service, `CfbCalculator`, snapshot builder, calculation release definition/registrar and `Live/Overtime/OvertimeProjector` rule handling.

**Add:** `CfbScoreDistribution` contract and deterministic artifact-backed possession scorer. First fit a simple possession/points-per-drive challenger; then add state-dependent late-game transitions only after that baseline is evaluated.

Model game duration/possession count jointly for both teams, using observed tempo, drive duration, offensive style and opponent effects. Model drive outcomes and durations conditional on field position, offense/defense, game state and rule era. Include defensive/special-team scoring explicitly. Do not assume independent Poisson team scores or mechanically alternate possessions after every event; turnovers, onside recovery, return scores and half boundaries need explicit transitions.

Competitive-state data estimate underlying strength. Late-game state data estimate leading-team pace, substitutions when observed, fourth-down decisions and trailing-team aggression. Do not drop blowout plays from the final-score simulator: they matter to large spreads and team totals. Missing backup participation is uncertainty, not an invented substitution probability.

The output is a normalized joint distribution `P(home_points, away_points)` plus seed/artifact/input hashes. Use a season-versioned overtime state machine for regulation ties. Reuse verified rule transitions, not the live estimator's insufficient-history fallback. Integer outcomes must obey scoring rules, include rare safety/try-return cases, and end with no tie for a completed game.

Derive expected team points, mean margin and total from that same distribution. Evaluate cover using `home_points − away_points + home_line > 0`; equality is a push. At −20.5, that means winning by at least 21. Team totals and alternate lines use their own thresholds and overtime contract. Report medians/quantiles distinctly from means.

Use deterministic sampling with at least 50,000 draws initially and report numerical standard error, or a normalized dynamic-programming grid if feasible. Increase draws only when numerical precision changes a pricing decision. Freeze algorithm version and seed; samples are numerical integration, not evidence of calibration.

Extend canonical team-total market type, selection/participant identity, serializers, API schema, evaluator and UI with compatibility tests across existing sports. Preserve expected-score consistency and distinguish raw model probabilities from any unvalidated output. Apply calibration through a coherent distribution/CDF approach where feasible; independently adjusted quotes must not violate complementarity or monotonicity.

**Must-pass tests:** probabilities sum to one; complementary win probabilities; push handling at integer lines; monotone alternate-line probabilities; sum of expected team scores equals expected total; favorite cover probability differs from win probability; deterministic hash round trip; terminal overtime rules; score/field-position/possession invariants; bounded inference memory. Run existing `OvertimePredictionTest`, `HistoricalTeamTotalsTest` and canonical market lifecycle tests.

### PR 7 — Matchups and the 200 signals

**Existing targets:** `Signals/CfbFootballSignalCatalog`, joint model/historical trainer, `CfbSignalContributionGrader`, snapshot builder and contribution reports.

Keep all 200 conditions as versioned hypotheses. Register feature families before fitting: pass/rush matchup; pressure/protection; possession pace; drive finishing; ball security; personnel; travel/rest/weather; late-game behavior. Do not count home/away copies as new football trends.

First repair training coverage diagnostics: expose counts rejected for missing prior ratings, absent source timestamps, incomplete history, and baseline mismatch. Residual labels must be generated by the exact candidate baseline on that fold's past-only inputs. Prior-season-FPI reconstructions are not interchangeable with archived weekly-FPI forecasts.

Fit a regularized joint residual model with penalties selected inside training folds. Evaluate group removal, and signal removal where supported, on later games. Store activation count, missing-input rate, coefficient stability, estimated contribution, uncertainty, baseline/artifact version and incremental held-out score. Report incremental predictive value, not causal attribution or a trend's unadjusted win rate. Unknown inputs remain distinguishable from a condition being false.

Travel implementation needs actual venue coordinates/time zones, rest days and kickoff in the visitor's home zone; do not assume departure time or arrival schedule. QB/lineup scenarios require observed availability evidence. Pressure is unavailable unless supplied or derived under a validated definition. Weather must be a forecast available before kickoff, not observed postgame weather.

**Acceptance:** correlated duplicate signals cannot multiply the same effect unchecked; unsupported families contribute zero adjustment with a stated reason; activating a verified signal changes only the documented scoring terms; performance gains survive group ablation on untouched games. Extend existing signal catalog/model/trainer tests.

### PR 8 — Calibration, pricing, comparison and promotion

**Reuse:** existing moneyline/spread datasets, residual calibration, spread assessment, frozen baseline/contribution reports, model artifacts and released decision recorder.

Fit calibration on held-out predictions produced by the same model version, separate from parameter training and the final test. Preserve a distribution of errors varying with pace, mismatch, early-season uncertainty and FCS status only where samples support it. Otherwise pool and disclose uncertainty. Large favorites cannot inherit validated cover claims solely from close games.

Report score RMSE/MAE/bias, margin and total errors, multivariate energy score for the joint score distribution, CRPS for margin/total distributions, Brier/log loss for quoted outcomes, reliability intervals, push frequency and interval coverage. Use proper smoothed probability estimates for log loss; a finite Monte Carlo sample having zero occurrences does not prove an event impossible. Report Weeks 0–3, later weeks, neutral/home/away, FBS–FCS, and absolute spread buckets `<20`, `20–28`, `28–35`, `35+`. Small cohorts remain descriptive; do not suppress valid point forecasts solely for cohort count.

Market decisions require an actual quote with timestamp, price, bookmaker and settlement rules. For unit stake: `EV = P(win) × net win payout − P(loss)`; pushes return the stake. Reuse existing pricing logic and freeze its version. Missing odds means unpriced, not an inferred edge. Display forecast lean independently of a priced recommendation. Track yield, drawdown and CLV with uncertainty and correlated same-game exposure; positive CLV alone is not proof of profit.

Proposed pre-registered release gates:

- Operational/data tests all pass; zero known target-outcome/timestamp leakage; reproducible artifacts.
- Primary objective: paired improvement in joint energy score over the explicitly labeled FPI distribution benchmark; use a kickoff-week block bootstrap. Require its 95% improvement interval to exceed zero on the untouched test set, plus no more than 0.25-point deterioration in aggregate margin or total MAE against the actual released point forecasts. Record this tolerance before testing.
- Test calibration through reliability curves/intervals and proper-score comparisons against current and simple baseline probabilities. Remove the existing broad `Brier <= 0.5` criterion as sufficient evidence by itself. A failed cohort withholds calibrated betting claims for that cohort, not every forecast.
- Prospectively shadow at least four football weeks and 200 distinct games, whichever takes longer, with frozen inputs and versions. This is a proposed minimum observation window, not a guarantee of statistical power. Extend it if uncertainty remains material; do not lower criteria after observing losses.
- Forecast promotion and priced recommendation activation are separate decisions. Prospective pricing results must not show a material regression, and any profitability claim requires adequate uncertainty support. Continue collecting large-spread and team-total evidence if their samples are insufficient.

Rollback selects the previous release/artifact for future outputs and regenerates eligible upcoming games; it never rewrites published historical forecasts. Infrastructure rollbacks retain accepted repaired data and additive schema. A quality failure triggers per-game fallback/stale labeling and a targeted alert, not silent new predictions from suspect inputs.

## 5. Commands, deployment sequence and scope control

The following are **proposed command contracts**, not commands to execute before implementation:

```text
cfb:audit-game-data --season=2026 --report=...
cfb:repair-game-data --season=2026 --only-failed --resume --max-games=...
cfb:build-drives --season=2026 --only-changed
cfb:export-scoring-training --from-season=... --to-season=... --as-of=...
cfb:train-scoring-challenger --manifest=... --fold-plan=...
cfb:evaluate-scoring-challenger --artifact=... --holdout-manifest=...
cfb:run-pregame-pipeline --season=2026 --days-forward=2 --resume
```

Each command must offer machine-readable stage results, bounded scope, explicit error codes, and safe restart semantics. Existing EPA/metric/generation commands become pipeline adapters rather than duplicate scheduled owners. Bootstrap old records as unverified and audit them in bounded batches; do not retroactively label them complete without checks.

Sequence: PR 1 → PR 2 for production reliability. PR 3 → PR 4 → PR 5 establish modeling evidence; PR 6 delivers the score distribution; PR 7 adds incremental matchups; PR 8 evaluates and promotes. Research exports can start earlier for inventory, but promoted models require the corrected contracts. Each PR is independently reviewable and has the tests above as its completion definition.

Planning estimates for one focused implementation stream: roughly 3–5 engineering days for PRs 1–2; 4–7 for normalization/export; 5–8 for opponent/possession modeling; 3–5 for signals, calibration and integration. Historical data acquisition and the prospective observation window are separate and may dominate elapsed time. Re-estimate after PR 4's coverage report. These are workload estimates, not a promised delivery date.

First production rollout: additive schemas, fixture tests and dry-run audit; replay the 311-game repaired cohort; deploy importer/quality checks; enable the stage workflow for a small set of upcoming games; verify restart behavior and freshness; expand to the slate. Keep the released scorer active throughout. Do not deploy unrelated dirty NFL/API changes from the current checkout; implementation uses an isolated, reviewed branch.

## 6. Operator and user-facing acceptance

One game view should show: projected team scores and ranges; model version and generation time; current line/price/book and quote age; forecast lean; recommendation state; major measured contributions; source completeness; optional unknowns; and an actionable failure reason when relevant. The operations view shows stage counts, oldest unfinished stage, retry state, provider failures and model freshness. Implementation details stay in the operations view.

Examples of acceptable outcomes:

- A valid source correction updates Indiana's accepted box score, invalidates affected metrics, and regenerates eligible future forecasts without manual command orchestration.
- A missing player is resolved from an authoritative athlete ID before stats import; an empty response cannot erase prior good stats.
- A game's missing market does not block the other 94 games, and that game retains a clearly unpriced forecast.
- Miami −20.5 has a predicted score distribution and cover probability with documented validation status, not a reused probability of winning.
- Every applied signal has source evidence, a fitted contribution and a frozen model version; no signal is called useful merely because it exists in the catalog.
- The last stored eligible quote for the same book/market is used consistently for closing-line comparison.

## 7. Remaining discovery work, with bounded deliverables

The code review and current-season repair are verified. Multi-season drive completeness, archived weekly ratings, weather forecasts, player availability history, bookmaker mapping coverage, and training-worker resource limits are not yet verified. PR 4 must return a populated coverage matrix and request/cost estimate; unsupported feature families are omitted from the first challenger rather than guessed. PR 2 must classify all unmatched odds events. PR 3 must measure actual state/drive availability before training.

The first implementable scope is therefore unambiguous: ship PRs 1–2 with regression fixtures from the repaired production failures, then build and evaluate the scoring challenger through the existing release lifecycle.
