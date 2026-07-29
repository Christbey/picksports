# Trusted Model Lifecycle

This workflow keeps model research, promotion, betting decisions, and public
recommendations separate. A trained artifact cannot affect a public prediction
unless it has passed chronological evaluation and has been explicitly promoted.

## Provenance Contract

Every training row must have:

- a final game and stable target hash
- a prediction feature snapshot
- a model run with configuration and code hashes
- `features_available_at <= game_start_at`
- either observed pregame timing or verified historical reconstruction
- a binary result for win-probability calibration

Every registered model has:

- a training run UUID
- configuration, code, and dataset hashes
- an immutable artifact path and SHA-256 hash
- an evaluation report path and SHA-256 hash
- an explicit challenger, eligible, promoted, retired, or invalidated status

Use `sports:report-model-lineage {artifact}` to print the full chain.
Admins can review the same lineage and live feedback at
`/admin/nfl-model-monitoring`.

## NBA Workflow

```bash
php artisan sports:backfill-snapshot-provenance --sport=nba
php artisan nba:report-training-readiness --minimum-rows=100
php artisan nba:export-training-data --path=storage/app/ml/nba_trusted_training_data.csv
php artisan nba:split-snapshot-dataset \
  --input=storage/app/ml/nba_trusted_training_data.csv \
  --output-dir=storage/app/ml/nba-trusted-splits
php artisan nba:train-win-probability-calibration-model \
  --input-dir=storage/app/ml/nba-trusted-splits
php artisan nba:evaluate-win-probability-calibration-rolling \
  --input=storage/app/ml/nba_trusted_training_data.csv \
  --artifact-id=<artifact-uuid> \
  --min-train-size=90 \
  --test-window-size=30 \
  --step-size=30
php artisan sports:evaluate-model-promotion <artifact-uuid>
```

The final promotion command is an evaluation only. Add `--promote` only after
reviewing the report. A promoted calibration artifact changes live output only
when `NBA_WIN_PROBABILITY_CALIBRATION_ENABLED` and
`NBA_WIN_PROBABILITY_CALIBRATION_APPLY_TO_LIVE_OUTPUT` are both enabled.

Without promotion, the artifact remains a shadow challenger. Baseline and
challenger probabilities are stored side by side.

## NFL Workflow

Historical profiles are resumable and distinguished in snapshot lineage:

```bash
php artisan nfl:backfill-historical-predictions \
  --from-season=2009 \
  --to-season=2025 \
  --season-type=2 \
  --profile=elo-only \
  --only-missing-profile \
  --regrade

php artisan nfl:backfill-historical-predictions \
  --from-season=2009 \
  --to-season=2025 \
  --season-type=2 \
  --profile=full-historical \
  --only-missing-profile \
  --regrade

php artisan nfl:export-training-data \
  --profile=elo-only \
  --path=storage/app/ml/nfl_elo_only_training_data.csv

php artisan nfl:export-training-data \
  --profile=full-historical \
  --from-season=2017 \
  --to-season=2025 \
  --feature-version=nfl-pregame-ml-v3 \
  --path=storage/app/ml/nfl_full_historical_training_data_v3.csv

php artisan nfl:compare-historical-profiles

php artisan nfl:register-historical-profile-artifact

php artisan sports:evaluate-model-promotion <profile-artifact-uuid> \
  --report=storage/app/ml/reports/nfl_historical_profile_comparison.json \
  --promote

php artisan nfl:split-snapshot-dataset \
  --input=storage/app/ml/nfl_full_historical_training_data.csv \
  --output-dir=storage/app/ml/nfl-full-historical-splits

php artisan nfl:train-win-probability-calibration-model \
  --input-dir=storage/app/ml/nfl-full-historical-splits

php artisan nfl:evaluate-win-probability-calibration-rolling \
  --input=storage/app/ml/nfl_full_historical_training_data.csv \
  --artifact-id=<artifact-uuid> \
  --min-train-size=1000

php artisan sports:evaluate-model-promotion <artifact-uuid>
```

The real tabular training and DigitalOcean runtime are documented in
`docs/nfl-ml-digitalocean.md`. A completed Python run is registered as one
immutable bundle:

```bash
php artisan nfl:register-tabular-model-run /artifacts/<model-run-id> \
  --dataset=storage/app/ml/nfl_full_historical_training_data.csv
```

NFL rolling evaluation uses complete held-out seasons by default. The optional
`--row-windows` flag restores fixed-size expanding windows for research.

The registered full-history profile always runs as a shadow challenger for
future games. Promotion makes its outputs eligible for tracking decisions but
does not replace the public NFL prediction. Each pregame snapshot stores both
sets of spread, total, and win-probability outputs with `active_source` fixed to
`baseline`.

```bash
php artisan nfl:generate-predictions \
  --season=2026 \
  --from-date=2026-08-06 \
  --to-date=2026-08-06
php artisan sports:record-shadow-bet-decisions \
  --sport=nfl \
  --artifact=<profile-artifact-uuid>
php artisan sports:settle-bet-decisions --sport=nfl
php artisan sports:report-model-feedback <profile-artifact-uuid>
```

## Promotion Policy

The default policy requires:

- at least three chronological windows
- the challenger to beat baseline in at least 60% of windows
- a positive average primary-metric improvement
- a positive average log-loss improvement for win probability
- no held-out window to exceed the configured regression ceiling
- market-specific eligibility for win probability, spread, and total
- at least 25 live pregame-safe observations and 10 settled shadow decisions
  before actual promotion

All normalized reports use `baseline - challenger`, so a positive delta means
the challenger is better. Legacy Laravel reports are converted at the boundary.

Promotion is never automatic. `sports:evaluate-model-promotion` marks a passing
artifact `promotion_eligible`; only `--promote` makes it eligible for
decision-time use. Whether a promoted artifact can change a live public output
is controlled separately by the sport-specific inference configuration.

## Decisions And Settlement

Shadow outputs flow into immutable, tracking-only decisions:

```bash
php artisan sports:record-shadow-bet-decisions --sport=nba
php artisan sports:settle-bet-decisions --sport=nba
php artisan sports:record-shadow-bet-decisions --sport=nfl
php artisan sports:settle-bet-decisions --sport=nfl
php artisan sports:report-model-feedback <artifact-uuid>
php artisan nfl:materialize-signal-observations --season=2026
php artisan nfl:grade-signal-observations --season=2026
php artisan nfl:report-signal-grades --from-season=2021 --to-season=2026
```

A tracking bet requires that promotion was already active at decision time, the
feature snapshot was pregame safe, the quote existed at decision time, and edge
met the configured threshold. Historical promotion cannot create a
retrospective bet.

Decisions are never public. Settlements retain actual profit, counterfactual
shadow profit, closing-line value, calibration error, and no-bet reasons. NBA
and NFL decision and settlement jobs run after their scheduled prediction and
grading stages.

## Current Research Result

The July 2026 NBA calibration challenger passed the multi-window policy but
remains intentionally unpromoted pending live shadow observations.

The NFL full-history prediction profile beat Elo-only across all 17 compared
seasons and is registered as promoted artifact
`da5cd0ae-de30-4e35-a2d8-d6639717d2a0`. Its first 2026 future-game shadow
observation was recorded for game `1668`: baseline win probability `0.495`,
challenger `0.473`, with the baseline retained as the public output. The
tracking decision was a no-bet because no pregame moneyline quote was available.

The first XGBoost challenger did not beat the full-history baseline on average
and remains blocked.

The replacement NFL v2 challenger is registered as artifact
`223b7233-5928-4d8c-a5cc-8113a8aa63bd`. It uses a four-season rolling history,
Platt-safe calibration selection, and a baseline-anchored blend. It beats
Picksports in two of three held-out seasons with positive average Brier and
log-loss improvement. Win probability and spread are offline eligible; totals
remain blocked. The artifact is not promoted because its own live-shadow
evidence thresholds have not been met.
