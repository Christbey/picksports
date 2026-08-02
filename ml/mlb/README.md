# Picksports MLB ML

This package trains and serves tabular MLB prediction models from an immutable,
point-in-time-safe Picksports snapshot export. It is isolated from Laravel and
does not connect to the application database.

## Bundle

The `mlb_tabular_bundle` contains:

- regularized logistic regression for home-win probability;
- XGBoost classification for home-win probability;
- XGBoost regression for expected home margin;
- XGBoost regression for expected total runs;
- Platt and isotonic calibrators for each classifier;
- an optional calibration-selected blend using Picksports and market anchors.

Missing optional baseline or market fields do not block training or inference.
When a selected blend receives no anchor for a row, its logistic and XGBoost
weights are renormalized rather than substituting a fabricated market value.

## Trusted Dataset Contract

CSV and Parquet inputs use `config/feature_schema.yaml`. Every row must include:

```text
snapshot_run_id
model_run_id
config_hash
code_version
game_id
season
game_start_at
features_available_at
pregame_safe
availability_status
feature_version
feature_hash
target_hash
target_home_win
target_home_margin
target_total_points
```

Only `observed_pregame` and `verified_reconstruction` rows are accepted.
`features_available_at` must be no later than `game_start_at`, and one stable
row per game is required. The loader hashes the source before and after reading
and can enforce a caller-supplied SHA-256.

The schema recognizes aliases emitted by the Laravel snapshot exporter, such
as `output_win_probability`, `output_predicted_spread`, and
`output_predicted_total`. Undeclared `feature_*` fields are ignored until they
are promoted into a versioned schema.

## Chronological Evaluation

MLB does not require five historical seasons. Splits are based on observed game
weeks and work with one season:

1. Base estimators fit on earlier observed weeks.
2. Platt and isotonic fit on the first chronological calibration segment.
3. Calibrator and classifier/blend selection use the later calibration segment.
4. The next week is evaluated once as a held-out weekly window.
5. The process advances one observed week for rolling evaluation.

The saved production candidate also uses separate chronological training,
calibration, and multi-week test ranges. Test targets never influence tuning,
calibration, blending, or champion selection.

After those metrics and choices are frozen, training performs a deployment
refit. The saved preprocessor, classifiers, and regressors use every eligible
settled row through the dataset cutoff, including the newest completed games.
The selected calibration methods are refit from pre-refit, out-of-sample
probabilities over the final calibration and test periods. Champion and blend
selection are not repeated. The manifest and evaluation report record the
cutoff, row count, seasons, observed weeks, calibration strategy, and an
explicit statement that all held-out metrics are pre-refit.

## Commands

Use Python 3.11:

```bash
cd ml/mlb
python3.11 -m venv .venv
source .venv/bin/activate
pip install --requirement requirements.lock.txt
pip install --no-deps --editable .

picksports-mlb-ml validate \
  --input /data/mlb_training.parquet

picksports-mlb-ml evaluate-rolling \
  --input /data/mlb_training.parquet \
  --output /artifacts/mlb-rolling-evaluation.json

picksports-mlb-ml train \
  --input /data/mlb_training.parquet \
  --expected-dataset-sha256 <sha256> \
  --output-dir /artifacts

picksports-mlb-ml predict \
  --run-dir /artifacts/<model-run-id> \
  --input /data/one-pregame-row.json

pytest
```

## Docker

```bash
docker build --tag picksports-mlb-ml:0.2.0 ml/mlb

docker run --rm \
  --volume "$PWD/storage/app/ml:/data:ro" \
  --volume "$PWD/ml/mlb/artifacts:/artifacts" \
  picksports-mlb-ml:0.2.0 \
  train --input /data/mlb_training.parquet --output-dir /artifacts
```

The image runs as a non-root user. Dataset mounts should be read-only and
artifact storage should be a separate writable volume.

## Artifact Contract

```text
artifacts/<model-run-id>/
  manifest.json
  evaluation.json
  prediction_example.json
  feature_schema.yaml
  preprocessor.joblib
  models/
    logistic_classifier.joblib
    xgboost_classifier.ubj
    xgboost_home_margin.ubj
    xgboost_total_points.ubj
  calibrators/
    logistic_regression_platt.joblib
    logistic_regression_isotonic.joblib
    xgboost_platt.joblib
    xgboost_isotonic.joblib
```

The manifest identifies `mlb_tabular_bundle`, package/module
`picksports_mlb_ml`, model run, artifact, dataset, feature schema, configuration,
source, code version, dependencies, chronological boundaries, selected models,
and a SHA-256 plus byte count for every artifact. Inference verifies that
inventory before loading any estimator.

The evaluation report type is `mlb_tabular_walk_forward_evaluation`. Offline
promotion remains advisory and always requires separate live shadow evidence.

## Inference Output

```json
{
  "home_win_probability": 0.614,
  "expected_home_margin": 1.3,
  "expected_total": 8.7,
  "home_cover_probability": 0.558,
  "over_probability": 0.472,
  "uncertainty": 0.081,
  "model_run_id": "run-id",
  "artifact_id": "artifact-id",
  "dataset_hash": "sha256",
  "feature_hash": "sha256"
}
```

`feature_market_home_spread` uses Picksports' normalized market-implied home
margin convention: a home favorite is positive. `home_cover_probability`
therefore estimates the probability that actual home margin exceeds
`feature_market_home_spread` directly. The shared Laravel decision layer
converts this normalized margin back to a sportsbook home line when needed.
`over_probability` estimates the probability that total runs exceeds
`feature_market_total`. Both use the corresponding calibration residual
standard deviation and return `null` when the market feature is absent.

This package predicts outcomes. Laravel remains responsible for quotes,
expected value, risk gates, bankroll policy, shadow decisions, and publishing.

## F3/F5 Period Models

The same package also trains independent three-outcome models for first-three
and first-five inning moneylines. Each model estimates away win, tie, and home
win probabilities. The tie probability is retained for push-aware expected
value; conditional home/away probabilities are used only for comparison with
two-way sportsbook prices.

```bash
php artisan mlb:backfill-period-history --from-season=2021 --to-season=2025
php artisan mlb:export-period-training-data \
  --from-season=2021 \
  --to-season=2026
php artisan mlb:train-period-models --from-season=2021 --to-season=2026
php artisan mlb:run-period-shadow
php artisan mlb:report-period-model-performance
```

Training uses rolling seasons. The season before each test season is split
chronologically into calibration-fit and calibration-selection segments, and
the test season is never used to choose the model or calibration method.
Registered period artifacts start as challengers with no promoted markets.
Laravel records their quote-aware outputs as private decisions and settles
ties as pushes from official inning line scores.
