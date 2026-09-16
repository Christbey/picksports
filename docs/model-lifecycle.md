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
`/admin/nfl-model-monitoring` and `/admin/mlb-model-monitoring`.

## Immutable Data And Deployment Manifests

Raw provider inputs and derived datasets live in private object storage. A
provider source-file record identifies the provider and dataset, content hash,
byte size, content type, compression, and immutable object key. Its import
manifest records timing, options, status, imported/skipped/error row counts,
and the source-file hash. Importing the same content again reuses the archived
source object instead of silently replacing it.

Large historical play partitions can be archived without loading the full
result into memory:

```bash
php artisan ml:export-historical-plays mlb 2025
php artisan ml:export-historical-plays nfl 2025 --format=jsonl --disk=ml-s3
php artisan ml:export-historical-plays nba 2025 --format=parquet
```

JSONL is the portable default. Parquet is produced only by a configured Python
runtime with PyArrow; requesting it without that dependency fails rather than
writing JSON data with a `.parquet` suffix. Each export manifest captures the
sport/season partition, actual encoding, row count, schema and schema hash,
data and manifest SHA-256 hashes, source table and maximum included source ID,
and private object URI. Object keys are content addressed. Repeated exports are
idempotent, and a hash mismatch is treated as immutable-object corruption.
Export never deletes or mutates source MySQL rows.

Derived feature datasets should add a feature manifest containing feature
names, types, nullability, timing semantics, availability cutoff, and the code
and configuration hashes. Every snapshot and trained artifact must reference
that exact feature-manifest hash. Artifact inventories similarly record the
model run, dataset and feature hashes plus every file's path, content type,
size, and SHA-256 hash.

Evaluation manifests use chronological windows and preserve train/test start
and end timestamps, row and game counts, metrics, and baseline deltas. A
deployment record separately identifies the artifact, environment and cohort,
activation and retirement timestamps, actor and reason, rollout or shadow
configuration, and rollback target. Model promotion is an eligibility decision;
it is not public recommendation publication.

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

## MLB Workflow

MLB uses game-week chronological windows so the current season can supply
training and live-shadow evidence without waiting for several complete seasons.

```bash
php artisan sports:backfill-market-quotes --sport=mlb --season=2026 --dry-run
php artisan sports:backfill-market-quotes --sport=mlb --season=2026
php artisan mlb:backfill-historical-predictions \
  --season=2025 \
  --profile=trusted-core-v1 \
  --only-missing-profile
php artisan mlb:export-training-data \
  --season=2025 \
  --season=2026 \
  --path=storage/app/ml/mlb_trusted_training_data.csv

cd ml/mlb
python3.11 -m venv .venv
source .venv/bin/activate
pip install --requirement requirements.lock.txt
pip install --no-deps --editable .
picksports-mlb-ml validate \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv
picksports-mlb-ml evaluate-rolling \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv \
  --output ../../storage/app/ml/mlb_rolling_evaluation.json
picksports-mlb-ml train \
  --input ../../storage/app/ml/mlb_trusted_training_data.csv \
  --output-dir /artifacts
```

Register the completed immutable run, then leave it in private shadow mode:

```bash
php artisan mlb:register-tabular-model-run /artifacts/<model-run-id> \
  --dataset=storage/app/ml/mlb_trusted_training_data.csv
php artisan mlb:run-tabular-shadow --artifact=<artifact-uuid>
php artisan sports:evaluate-model-promotion <artifact-uuid>
```

`MLB_ML_SHADOW_ENABLED=true` enables scheduled inference. Leave
`MLB_ML_SHADOW_ARTIFACT_ID` empty when `MLB_ML_SHADOW_AUTO_SELECT=true` so the
database-backed shadow cohort can advance automatically. The initial pass runs
after the morning baseline.
Additional passes run after each in-season odds cycle so the model probability,
exact market line, quote, and decision share a reproducible pregame horizon.
All resulting decisions remain private and tracking-only.

### MLB F3/F5 workflow

F3 and F5 use official inning line scores and separate three-class models for
away win, tie, and home win. The period artifact is deliberately independent
from the full-game artifact:

```bash
php artisan mlb:backfill-period-history --from-season=2021 --to-season=2025
php artisan mlb:train-period-models --from-season=2021 --to-season=2026
php artisan mlb:run-period-shadow
php artisan mlb:report-period-model-performance
```

The decision layer compares conditional home/away probabilities with the
captured two-way quote, but calculates expected value from absolute win and
loss probabilities so the modeled tie remains a push. Period artifacts have
no promoted markets at registration. Promotion requires stable chronological
windows plus enough settled live quote decisions with positive ROI and CLV.

## Weekly MLB And NFL Automation

The in-season scheduler runs the complete challenger lifecycle:

- MLB: Monday at `06:40` Central Time
- NFL: Tuesday at `12:40` Central Time, after Monday games and readiness jobs

The scheduled command runs on the DigitalOcean application host using the
sport's configured ML Python binary. Training is isolated in a child process
with a bounded timeout and thread count; it never receives database
credentials because the Python package reads only the immutable export.

Each `sports:train-weekly-model-challenger` cycle:

1. settles the prior active challenger's promotion evaluation;
2. reconstructs missing trusted current-season NFL rows when applicable;
3. exports to a new atomic dataset and fails when no rows qualify;
4. hashes the dataset, schema, package source, and training configuration;
5. skips an exact duplicate fingerprint;
6. trains with bounded CPU threads and a four-hour timeout;
7. verifies the returned run ID, artifact type, and dataset hash;
8. registers the immutable artifact, dataset, and evaluation report;
9. evaluates the new artifact without bypassing any promotion gate; and
10. assigns an offline-eligible challenger to the private shadow cohort.

The Python training stage keeps chronological evaluation untouched, then
performs a separate deployment refit through the latest settled training row.
This allows the deployed challenger to learn from the current season without
leaking those rows into its reported held-out metrics.

Every automation cycle is recorded as a `weekly_training_cycle` model run with
its current stage, dataset hash, fingerprint, artifact ID, timestamps, and
terminal status. Failed cycles retain a private `failure.json` in
`storage/app/ml/automated-training/<sport>/<cycle-id>`.

`MLB_ML_AUTO_PROMOTE_ENABLED` and `NFL_ML_AUTO_PROMOTE_ENABLED` control whether
the previously selected challenger may be promoted after every offline and live
gate passes. Promotion still cannot publish a pick directly: model outputs flow
through private `bet_decisions`, price, edge, uncertainty, risk, and bankroll
rules. Set either flag to `false` to keep automated training and shadowing while
requiring manual promotion.

Use these commands for an immediate run or a safe test:

```bash
php artisan sports:train-weekly-model-challenger mlb
php artisan sports:train-weekly-model-challenger nfl
php artisan sports:train-weekly-model-challenger nfl --no-promote --retain-workdir
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

Live requirements count distinct games per artifact and market. Repeated
pregame snapshots for one matchup cannot inflate promotion evidence.

All normalized reports use `baseline - challenger`, so a positive delta means
the challenger is better. Legacy Laravel reports are converted at the boundary.

`sports:evaluate-model-promotion` marks a passing artifact
`promotion_eligible`; only an explicit `--promote` evaluation makes it eligible
for decision-time use. The weekly coordinator may issue that explicit
evaluation for the selected challenger when the sport's auto-promotion flag is
enabled. Whether a promoted artifact can change a live public output remains
controlled separately, and MLB/NFL automated outputs stay private.

## Decisions And Settlement

Shadow outputs flow into immutable, tracking-only decisions:

```bash
php artisan sports:record-shadow-bet-decisions --sport=nba
php artisan sports:settle-bet-decisions --sport=nba
php artisan sports:record-shadow-bet-decisions --sport=nfl
php artisan sports:settle-bet-decisions --sport=nfl
php artisan sports:record-shadow-bet-decisions --sport=mlb
php artisan sports:settle-bet-decisions --sport=mlb
php artisan sports:report-model-feedback <artifact-uuid>
php artisan nfl:materialize-signal-observations --season=2026
php artisan nfl:grade-signal-observations --season=2026
php artisan nfl:report-signal-grades --from-season=2021 --to-season=2026
```

NFL signal grading is incremental and bounded. The scheduled command selects
only finalized observations with missing outcome grades, an NFL result change,
or a new/changed settlement. Updating live odds on `nfl_games` does not make a
historical grade stale. Defaults are 250 rows per database batch and 1,000
observations per run; the hard ceiling is 5,000. Use `--refresh` only for an
intentional, bounded rebuild, for example:

```bash
php artisan nfl:grade-signal-observations --season=2026 --limit=1000 --batch-size=250
php artisan nfl:grade-signal-observations --season=2026 --refresh --limit=1000
```

If the command reports pending work remains, let the next scheduled run drain
it or repeat the same bounded command. Do not use an unbounded full-season
refresh in Laravel Cloud.

A tracking bet requires that promotion was already active at decision time, the
feature snapshot was pregame safe, the quote existed at decision time, and edge
met the configured threshold. Historical promotion cannot create a
retrospective bet.

Decisions are never public. Settlements retain actual profit, counterfactual
shadow profit, closing-line value, calibration error, and no-bet reasons. NBA
and NFL decision and settlement jobs run after their scheduled prediction and
grading stages.

### NFL production rollout

The confirmed Week 1 Laravel Cloud configuration incident and the exact Week 1
repair, Week 2 activation, verification, and rollback sequence are maintained in
the [NFL canonical production incident runbook](operations/nfl-production-incident-runbook.md).

NFL canonical generation is deliberately opt-in. Set these Laravel Cloud
environment values while keeping canonical reads on the legacy path:

```dotenv
PREDICTION_LIFECYCLE_NFL_CANONICAL_PIPELINE=true
PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false
NFL_TRUE_EPA_ENABLED=true
NFL_TRUE_EPA_BACKFILL_BEFORE_GENERATION=true
NFL_PREGAME_MARKET_MAXIMUM_QUOTE_AGE_MINUTES=60
NFL_PREGAME_MAXIMUM_PREDICTION_AGE_MINUTES=360
NFL_RESEARCH_PIPELINE_ENABLED=true
```

Deploy the additive migrations, clear cached configuration, and perform the
pregame production pass:

```bash
php artisan migrate --force
php artisan config:clear
php artisan nfl:register-calculation-release --release-version=1.1.0 --replace-active --effective-at=<timestamp-before-kickoff>
php artisan nfl:sync-odds --days=8
php artisan nfl:research-pipeline --days-forward=7 --limit=8 --ingest-only
php artisan nfl:research-pipeline --days-forward=7 --no-ingest --limit=4
php artisan nfl:run-pregame-pipeline --season=2026 --days-forward=8
php artisan nfl:report-canonical-cutover-readiness --season=2026 --fail-on-not-ready
```

Regular-season and postseason NFL games are included; preseason is excluded.
Keep `PREDICTION_LIFECYCLE_NFL_CANONICAL_READS=false`: data readiness proves
operational coverage, not that the separate canonical model improves the richer
legacy model. Promote reads only after explicit model comparison and API review.
Never backdate a calculation release or create a canonical prediction
for a game that has already kicked off.

Canonical NFL input snapshots freeze the selected pregame quotes and canonical
evaluation grades ATS/total comparisons from those immutable inputs. Mutable
`nfl_games.odds_data` is only the current market container and must not be used
to reconstruct a historical line. A canonical NFL market input is available
only when the provider `last_update` timestamp is within
`NFL_PREGAME_MARKET_MAXIMUM_QUOTE_AGE_MINUTES` and the same captured market has
complete, priced home/away spread and over/under total pairs. That provider
timestamp is persisted in each quote's metadata and is preferred for freshness;
when the feed omits it, the persisted ingestion `captured_at` is the
authoritative conservative observation proxy. The readiness report applies the
same freshness and pair-coverage contract to every upcoming
regular-season or postseason slate game as of that immutable canonical snapshot's capture
time; a valid frozen input does not become invalid merely because wall-clock
time later exceeds the quote-age window. Separately, readiness rejects legacy
or canonical forecasts older than the configured six-hour forecast limit.
Successful fresh fetches of unchanged NFL markets periodically create a new
immutable observation without replacing the older entry record.

The legacy NFL prediction remains the current deterministic pro-signal
authority during cutover. The true-EPA blend is applied by this legacy
prediction/recommendation layer; the canonical forecast calculator does not
currently apply true EPA to its projected score, spread, total, or win
probability. Canonical predictions provide immutable forecast and grading
lineage, while legacy predictions provide the true-EPA and recommendation
disposition used to decide whether a tracking selection may be released.
Readiness therefore requires every slate game to have either `true_epa.applied`
or an explicit `analysis_layer.eligibility.status=hold` with
`missing_true_epa` so missing data is never silent. A hold is auditable but is
never recommendation-eligible: it blocks final cutover readiness, and
`nfl:generate-predictions` exits nonzero after reporting the held game IDs so
production cannot silently treat the slate as complete.

The ordered production chain refreshes eight days of odds, generates legacy
forecasts and dispositions, then records canonical forecasts and checks readiness.
It runs at 10:20, 12:20, 16:20, and 20:20, plus hourly at :20 from 06:00–23:00
when a kickoff is within 24 hours. The standalone NFL odds job is removed to
avoid duplicate calls. Weather refreshes at 06:05, 10:05, 14:05, 18:05, and 22:05.
Stages stay in the scheduler process. An explicit legacy hold does not suppress
canonical observations for other games, but the overall pipeline still fails.
The independent 10:50 sentinel remains enabled even when canonical writes are
disabled and shares the pipeline overlap mutex. Signal grading runs hourly at
:35 with a 1,000-observation limit and 250-row batches.

Every generated legacy forecast also records a disposition for each market
without an official candidate: `model_no_bet` when a real pregame market and
feature snapshot are available, otherwise `model_hold` with evidence gaps.
Both have `is_bet=false`. Complete no-bet observations receive counterfactual
W-L after final scores; held observations are excluded from settlement.
Dispositions are immutable per feature snapshot and quote, and later official
release preserves the earlier pass or hold history.

Immediately after generation, an eligible
`official_candidate` with an exact, already-observed pregame quote creates at
most one private tracking decision per prediction and market. AI availability
is not a prerequisite. The quote must still be within the configured maximum
age and have a same-book, two-sided counterpart for the selected market. These
decisions set `is_bet=true` for W-L and
model-profit tracking but retain `is_tracking_only=true`, `is_public=false`,
and `explanation.execution_status=not_recorded`; they are not evidence that a
sportsbook wager was executed. Settlement metadata reports those concepts
separately as `tracked_bet` and `actual_bet_placed`. NFL signal reports show
tracked model selections as W-L-P plus model ROI, even when no wager was
executed; `actual_settlement_sample` and `actual_roi` remain separate JSON
fields and only include non-tracking wagers.

After scores are final, let the scheduler settle and grade incrementally or run
the bounded sequence manually:

```bash
php artisan sports:settle-bet-decisions --sport=nfl
php artisan nfl:materialize-signal-observations --season=2026
php artisan nfl:grade-signal-observations --season=2026 --limit=1000 --batch-size=250
php artisan nfl:report-signal-grades --season=2026 --json
```

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
