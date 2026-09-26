# CFB scoring implementation and rollout

September 25, 2026 · Branch `codex/cfb-scoring-pipeline`

The code below is implemented in the isolated CFB worktree. Production has not been migrated, repaired, trained, or switched by this implementation task. The challenger defaults to disabled; registering an artifact does not replace the released forecast or approve bets.

## What changed across packages 1–8

| Package | Implemented behavior | Evidence required before production acceptance |
|---|---|---|
| 1. Data completeness | Validates both teams, player identities, passing/rushing reconciliation including Team credits, play pagination and final scores. Archives candidate responses. Valid imports preserve stable player/team/play rows; partial responses retain accepted data. Adds audit and bounded repair commands. Scheduled details checks consider quality evidence, not just row existence. | Replay the repaired 311-game cohort, measure discrepancies, and verify provider-specific called games and ID changes. Historical records start unverified. |
| 2. Recovery and closing lines | Persistent repair, normalization and forecast stages; concurrency locks; failed stages retry; actual published output is required. History repair precedes Elo and metrics. Missing odds no longer stops forecasts. Daily and hourly canonical generation use the validated pipeline; legacy scheduled rebuilds are disabled when the canonical pipeline is enabled. Frozen decisions resolve the last stored pregame quote for the same book, participant and recorded contract, excluding late imports. | Verify restart behavior and memory on the production worker. Classify the previously unmatched odds events. Measure freshness before increasing cadence. |
| 3. Drives and EPA | Source play states, drive boundaries, return scores, tries, quarter/half distinctions, administrative-event exclusion and score reconciliation. EPA uses only earlier-season state values; removes same-game next-score and fabricated-zero fallbacks. Past-season baseline training is explicit. | Measure drive/state coverage and fit a prior-season baseline on accepted data. Unseen states remain null. |
| 4. Historical evidence | Hashed immutable exports with accepted and derived availability times, frozen original FPI/signals, source hashes, quote identities and coverage exclusions. Explicit complete-season holdout selection for multi-season exports. | Inventory actual eligible seasons and original snapshots. Retrospective corrected data is labeled; it is not presented as a historical live forecast. |
| 5. Opponent adjustment | Separate pooled offense/defense pass/rush models, observed EPA/success/explosive/sack features, pre-play situation adjustment, learned drive scoring and pace. Penalty, recency and FPI mixture selected on earlier validation weeks. | Fit on real data; inspect opponent connectivity, early-season and FCS coverage. Unknown opponents remain unavailable in the challenger; the existing released fallback remains visible. |
| 6. Joint score distribution | One possession simulation produces both team scores, margin, total and win/cover/push/team-total probabilities. Empirical possession order and late score-state adjustments; NCAA overtime eras use observed applicable outcomes. Team-total canonical markets are supported in grading, API and the game page. | Validate score ranges and runtime on real slates. Missing overtime evidence produces an explicit unavailable result. This is an empirical possession model, not a full field-position/clock simulator. |
| 7. Learned signals | Uses the existing 200-rule catalog. Baseline-matched past-week residuals, observed-only inputs, regularized joint fitting, support/missing counts, family-removal refits and per-signal week-bootstrap coefficient diagnostics. Unsupported/no-gain signals contribute zero. | Establish incremental value on real untouched games. Bootstrap intervals are descriptive after selection, not causal confidence or proof of profitable trends. No new weather, roster, pressure or travel observations are invented. |
| 8. Calibration and promotion | Separate calibration weeks; joint-distribution temperature calibration; score RMSE/MAE/bias, energy/CRPS, Brier/log loss, reliability intervals, early-season/large-spread cohorts, quote-specific EV and settlement probabilities. Hashed artifacts; frozen shadow forecasts and benchmark; prospective comparison; explicit forecast promotion/rollback. | Real held-out and prospective evidence. Forecast promotion requires at least 200 distinct games over four kickoff weeks, paired distribution improvement, point-error tolerances, calibration evidence and Brier comparison. Betting activation remains withheld pending independent priced-market validation. |

These are code deliveries, not claims that every acceptance fixture or operating objective in the original proposal has already been demonstrated on production data.

## Operating flow

The existing scheduled pregame command now checks and repairs relevant completed games, calculates EPA/drives, refreshes Elo and metrics, refreshes prices/weather, and generates each upcoming game independently. It records a forecast as complete only when a fresh published prediction exists. Results retain source, artifact and prediction identifiers.

A missing odds feed does not stop score forecasts. Indispensable shared membership/rating/metric failures still stop the run; unreliable team personnel or incomplete historical inputs block affected games with a recorded reason. Those failures are not silently relabeled as healthy. The ledger covers repair, normalization and forecast stages; shared refreshes retain existing cache/command controls. It is not yet a fully queued stage graph with worker heartbeats.

The existing hourly refresh cadence remains. The proposed ten-minute freshness objective has not been measured or claimed. Live scoring remains on its existing schedule. Offline training is deliberately not placed on the 512 MB application worker.

## Deployment sequence

1. Deploy this isolated branch after review. Do not deploy the unrelated dirty NFL/API checkout. Apply the additive migration before activating the changed scheduled commands.
2. Set `CFB_SOURCE_DISK` and `CFB_MODEL_DISK` to a configured durable disk in production. Local defaults are for development; ephemeral application storage is unsuitable. The prediction process must be able to read the registered artifact disk.
3. Leave `CFB_SCORING_ARTIFACT_ID` empty while bootstrapping quality records and validating operations. The released scorer continues to provide forecasts.
4. Audit and repair accepted history in bounded batches. Start with one worker. A repair exit code of 2 means unresolved or unprocessed games remain, even if its processed subset succeeded.
5. Fit the EPA baseline from an earlier season, rebuild affected play values and drives, and inspect coverage. Source corrections invalidate dependent training evidence.
6. Export and train offline. Review exclusions and original-snapshot coverage, then register the artifact through the application command. Enable its ID for shadow capture. Use persistent artifacts shared with production, not a developer-only path.
7. Generate fresh forecasts and collect prospective results. Review evaluation reports; promote only if the registered artifact passes its gates. Rollback changes future forecasts and requires regenerating upcoming games. Historical predictions remain immutable.

Example commands below are operator instructions, **not commands executed against production during this task**. Choose actual verified years, timestamps, IDs and persistent output paths.

```sh
php artisan migrate --force
php artisan cfb:audit-game-data --season=2026 --report=/data/cfb-audit.json
php artisan cfb:repair-game-data --season=2026 --only-failed --resume --max-games=50 --dry-run
php artisan cfb:repair-game-data --season=2026 --only-failed --resume --max-games=50
php artisan cfb:train-epa-baseline --season=2026 --source-season=2025
php artisan cfb:calculate-play-epa --season=2026 --rebuild
php artisan cfb:build-drives --season=2026 --only-changed
php artisan cfb:run-pregame-pipeline --season=2026 --days-forward=2 --dry-run
php artisan cfb:run-pregame-pipeline --season=2026 --days-forward=2 --resume
php artisan cfb:pipeline-status
```

For each exported historical season, accepted drives must have been built. The trainer requires at least 12 kickoff weeks and sufficient eligible games; missing history is reported, not synthesized. A multi-season export requires an explicit completed `--test-season`. March 1 of the following year is a conservative calendar check, not proof of schedule coverage. The coverage report still must be inspected.

```sh
php artisan cfb:export-scoring-training --from-season=2022 --to-season=2026 --test-season=2025 --as-of=2026-09-25T23:00:00Z --output=/data/cfb-training-v1.jsonl
php artisan cfb:train-scoring-challenger --manifest=/data/cfb-training-v1.jsonl.manifest.json --output=/data/cfb-model-v1.json --retrospective
php artisan cfb:evaluate-scoring-challenger --artifact=ARTIFACT_ID --report=/data/cfb-validation.json
php artisan cfb:scoring-report --game=GAME_ID --bookmaker=BOOK_KEY
php artisan cfb:promote-scoring-challenger --artifact=ARTIFACT_ID
php artisan cfb:promote-scoring-challenger --artifact=ARTIFACT_ID --rollback
```

`--retrospective` is necessary when repaired records were first accepted after the old prediction dates. It permits reconstructed historical training/research; it cannot justify historical performance claims. A current artifact may shadow future games once all its evidence actually exists. In that case, promotion requires a positive prospective paired energy-score interval instead of accepting the reconstructed historical comparison. This is an explicit alternative to the original proposal's strict recorded-time historical gate; prospective requirements are retained.

Parameter selection, calibration and the final historical test use separate chronological periods. The deployment artifact then refits ratings on data available at export time with the selected hyperparameters frozen. Its historical report belongs to the evaluation fit, not the refit. Prospective evidence must therefore come from that exact registered deployment artifact. Replacing or retraining the artifact starts new artifact-specific prospective evidence.

The new calculation release is `1.7.0`. Earlier release calibrations must not be treated as validation of the possession model. Forecast promotion and betting activation are separate. The new scorer currently emits a market-validation-required state even after forecast promotion; automatic recommendation activation is deliberately not implemented without its evidence.

## Validation performed locally

- CFB, CFB ESPN imports, shared NFL imports, canonical predictions and EPA baseline regression suite: 388 tests passed (3,232 assertions), including the final scheduler/status addition.
- Shared scheduling regression suite: 29 tests passed (320 assertions). Total PHP coverage for this task: 417 passing tests and 3,552 assertions.
- Six offline model unit tests passed, including temporal exclusion, shrinkage, coherent scores, overtime evidence and scoring rules.
- PHP/Python scoring parity tested after FPI mixing and calibration, using independent 50,000-draw simulations and numerical tolerances.
- Full synthetic training produced an immutable challenger artifact. Synthetic results are plumbing verification, not evidence of forecasting accuracy or betting profit.
- Frontend production build and full TypeScript check passed. No production database writes, artifact promotion or bet approvals were performed.

## Remaining acceptance work

The production cohort replay, historical coverage inventory, odds-mapping audit, baseline fitting, real model training, operational memory/freshness measurements and prospective observation period remain. The UI adds team-point means and saved time; the broader operator dashboard, score-range presentation, individual player-prop model, richer EPA interpolation, lineup scenario modeling and a dedicated priced-market activation gate are not delivered by this branch. These limits remain explicit rather than being replaced by assumed data or favorable probability labels.
