# NFL game simulation plan

## Objective and boundaries

Implement a score-distribution simulator first. It is a shadow research tool, not a drive/play engine or a source of approved bets. Existing forecasts and approval rules remain authoritative. Do not call simulated frequencies calibrated probabilities or independent evidence against a market already blended into the forecast.

## Stage 1: reproducible score distributions

Freeze a pregame prediction snapshot and exact bookmaker quotes. Convert the final expected home margin M and total T to team scoring means (T+M)/2 and (T-M)/2. Reject nonfinite, negative or impossible inputs. Retain model version, source snapshot ID, actual observation timestamps, kickoff, seed, iteration count and configuration hash. Use an isolated random generator; never change global RNG state.

Use paired historical home/away score residuals from observed pregame snapshots of the same model. Require completed regular-season games, original predictions available before kickoff, and results observed at least 24 hours before the analysis cutoff. Deduplicate games. Center the residuals so a historical bias does not silently overwrite the supplied mean forecast. Sampling pairs preserves empirical shared game variability. Exclude the target and future observations. A same-model corpus threshold is a fitting requirement, not proof of calibration.

When history is insufficient, hold by default. An explicitly requested non-persisting preview may instead use disclosed provisional correlated normal assumptions. Never silently fit against mutable prediction rows or apply arbitrary injury/QB adjustments.

Generate integer nonnegative final scores, map the unattainable one-point team score to two, and disclose the resulting rounding/clipping effects. Historical targets include overtime, so do not append another overtime simulation. Keep tied final games explicit. Return score, margin and total percentiles; outright home/away/tie frequencies; spread cover/loss/push and total over/under/push frequencies for each frozen quote. Support integer and half-point lines. Do not store individual simulation paths.

## Stage 2: frozen shadow observations and evaluation

Reuse prediction_feature_snapshots and prediction_evaluations with a distinct simulation blend version and deterministic run identity. Simulation snapshots reference their parent feature snapshot rather than the nfl_predictions table, so generic latest-forecast readers cannot consume simulation outputs. Preserve original model evidence and mark simulated outputs as uncalibrated, bet_eligible=false. Snapshot recording is explicit, pregame-only, transactionally rechecked and append-only. An identical request reuses its observation. No public API or scheduler is changed initially.

Grade only finalized games with results older than the maturity window. Store the first evaluation immutably. Report paired margin/total MAE, outright three-outcome Brier score, frozen spread/total outcome Brier scores, interval coverage, push frequencies and point-error comparisons against frozen market references. These are descriptive; no profitability or causal uplift claim. Report sample size and ungraded observations. Chronological fitting uses only information available before each historical target; a historical replay is not an originally observed prospective forecast.

## Stage 3: validation and promotion gates

Collect prospective shadow observations; review probability calibration and interval coverage on held-out chronological games and by favorite size, total and QB uncertainty. Compare the existing forecast, market-implied reference where available, fitted simulation and provisional baseline on the same games. Record missing data and rejected runs. Audit at least selected source records and outcomes manually. Simulation count controls Monte Carlo precision, not model accuracy. Promotion requires a separate reviewed decision; no automatic change to approval status.

## Stage 4: optional drive engine

Audit play/drive coverage, revisions, clocks and field position before implementation. Fit possession count, starting position, drive duration, scoring/turnover outcomes and game-state behavior. Implement explicit rules for overtime and scoring decisions. Model coherent QB availability scenarios from frozen evidence, with no invented scenario probabilities. Benchmark against Stage 1 and retain it only if held-out performance or useful scenario explanation improves.

## Delivery and verification

Deliver an isolated simulator, bounded same-model historical fitter, preview/record/grade/report commands, append-only storage guards and focused regression tests. Keep default shadow capture disabled. Test reproducibility, global RNG isolation, shared variability, settlement sign conventions, pushes/ties, interval quantiles, invalid input rejection, historical leakage and deduplication, pregame-only persistence, idempotency and immutable grading. No provider refresh, paid AI calls, production writes, deployment or automatic scheduling is needed to implement/test this stage.

## Implemented Stage 1–2 interface

- `php artisan nfl:simulate-game GAME_ID`: read-only preview; default 10,000 draws. Requires a source forecast observed within 36 hours and at least 200 same-family historical games. History is frozen at the original forecast generation time, not the later preview request. Actual kickoff from the provenance resolver governs temporal checks; the recorded source start is retained in its original snapshot for audit.
- `--basis=pre_market`: use the source snapshot's recorded pre-market outputs. Missing outputs cause a hold. This removes the direct market blend only and does not certify independence from all market effects.
- `--provisional`: explicitly opt into unfitted normal assumptions for a read-only preview when history is insufficient. This is not a validated betting probability or a replacement for the historical fitter. Provisional runs cannot be captured.
- `--record`: capture a fitted observation only when `NFL_GAME_SIMULATION_CAPTURE_ENABLED=true`. Capture remains disabled by default. The first observation per game/basis/engine version is retained; a different later request cannot replace it. Identical repeated requests reuse it.
- `php artisan nfl:simulate-game --replay=SNAPSHOT_ID`: reproduce the frozen inputs, seed and quotes and verify the output hash without writing.
- `php artisan nfl:simulate-game --grade --limit=100`: grade mature final observations, skipping already graded records. The first evaluation is immutable; later official corrections require separately versioned review.
- `php artisan nfl:simulate-game --report --limit=1000`: aggregate bounded observations separately by engine version, forecast basis and source model version. Report paired point error, three-outcome Brier scores, 90% interval coverage and market-specific push/line-error summaries. A quote count is not an independent game count.

Replace GAME_ID/SNAPSHOT_ID with existing identifiers. Preview failures return an explicit hold or input error. Stale/missing quotes produce no market result rather than fabricated lines. A changed listed QB that conflicts with the frozen model input holds the preview; ordinary projected starter wording alone does not. No commands are automatically scheduled and no public game/prediction API contract changes.

The historical residual corpus and compact distributions are stored, not individual simulation paths. Existing production predictions, approval states and playoff simulation behavior are not modified. Prospective calibration, an actual historical performance study and any promotion remain Stage 3 work after sufficient suitable observations exist. The optional drive engine is not part of this first implementation.
