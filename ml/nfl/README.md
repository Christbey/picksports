# Picksports NFL ML

This package trains real tabular NFL prediction models from an immutable,
point-in-time-safe Picksports export. It is deliberately isolated from the
Laravel application and does not read the application database.

## Models

- Regularized logistic regression for home-win probability
- XGBoost classifier for home-win probability
- Chronology-selected blend anchored to the current Picksports probability
- XGBoost regressor for home margin
- XGBoost regressor for total points
- Platt and isotonic probability calibration

Each walk-forward window uses complete seasons:

1. Fit base models on the configured rolling historical window.
2. Use the next season only for calibration.
3. Fit calibrators on the first chronological calibration segment.
4. Select Platt or isotonic on the later calibration segment.
5. Refit calibrators on the complete calibration season.
6. Evaluate once on the next held-out season.

The default v2 schema uses the four most recent complete training seasons and
caps the combined logistic/XGBoost blend weight at 50%. The current Picksports
probability therefore remains the majority anchor until the challenger proves
it can generalize.

The held-out season never influences classifier or calibrator selection.

## Input Contract

The default schema is
[`config/feature_schema.yaml`](config/feature_schema.yaml). It matches the
current `nfl:export-training-data --profile=full-historical` export and rejects
undeclared `feature_*` columns. New roster, player, weather, trend, or market
features must be explicitly added to a new schema version before training.

The loader:

- accepts CSV or Parquet;
- computes SHA-256 before and after reading;
- optionally requires a caller-supplied SHA-256;
- requires stable feature and target hashes;
- requires `features_available_at <= game_start_at`;
- requires every row to be marked pregame safe;
- requires one stable row per game.

## Local Commands

Use Python 3.11.

```bash
cd ml/nfl
python3.11 -m venv .venv
source .venv/bin/activate
pip install --requirement requirements.lock.txt
pip install --no-deps --editable .

picksports-nfl-ml --help
picksports-nfl-ml validate \
  --input ../../storage/app/ml/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml

picksports-nfl-ml train \
  --input ../../storage/app/ml/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml \
  --output-dir ./artifacts

picksports-nfl-ml diagnose \
  --input ../../storage/app/ml/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml \
  --output ./artifacts/nfl-2024-diagnostic.json \
  --target-season 2024 \
  --training-windows expanding,4,5,6

picksports-nfl-ml predict \
  --run-dir ./artifacts/<model-run-id> \
  --input ./one-pregame-feature-row.json

pytest
```

For a provenance-locked training job, pass the hash printed by the Laravel
export:

```bash
picksports-nfl-ml train \
  --input /data/nfl_training.parquet \
  --expected-dataset-sha256 <sha256> \
  --output-dir /artifacts
```

## Docker

```bash
docker build --tag picksports-nfl-ml:0.1.0 ml/nfl

docker run --rm \
  --volume "$PWD/storage/app/ml:/data:ro" \
  --volume "$PWD/ml/nfl/artifacts:/artifacts" \
  picksports-nfl-ml:0.1.0 \
  train \
  --input /data/nfl_full_historical_training_data.csv \
  --output-dir /artifacts
```

The container runs without root privileges. Mount datasets read-only and use a
separate writable artifact volume. Linux installs the official CPU-only
XGBoost distribution to avoid shipping unused GPU libraries. On DigitalOcean,
the completed run directory can be uploaded unchanged to private Spaces.

## Artifact Layout

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

The manifest records the model run, artifact ID, code/config/dataset/schema
hashes, a package-source hash, dependency versions, seed, selected classifier,
blend weights, calibrators, training window, held-out season, and a SHA-256 for
every artifact file. A dirty working tree is marked in `code_version`; the
source hash remains the exact identity of the Python training code.

## Prediction Contract

```json
{
  "home_win_probability": 0.614,
  "expected_home_margin": 3.8,
  "expected_total": 44.7,
  "home_cover_probability": 0.558,
  "over_probability": 0.472,
  "uncertainty": 0.081,
  "model_run_id": "run-id",
  "artifact_id": "artifact-id",
  "dataset_hash": "sha256",
  "feature_hash": "sha256"
}
```

Cover probability requires `feature_market_home_spread`, expressed as the home
team's market-implied margin. Over probability requires
`feature_market_total`. Missing market inputs produce `null`, not a fabricated
betting probability.

`uncertainty` combines calibrated probability entropy and disagreement between
the logistic and XGBoost classifiers. It is a monitoring signal, not a bet.
