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

After evaluation freezes the tuned parameters, classifier, calibrators, and
blend, the package performs a deployment refit. The saved preprocessor,
classifiers, and regressors are fit on every eligible settled row through the
dataset cutoff. The selected calibrators remain the chronology-fitted
pre-refit objects, avoiding in-sample calibration leakage. No metric in
`final_holdout` or `walk_forward` is recomputed from the deployment-refit
models.

The latest season is included in evaluation only when an optional
`season_complete`/`is_season_complete` column marks it complete, or when its
row and week coverage meets or exceeds recent completed seasons. A partial
current season is excluded from held-out metrics but included in the
deployment refit. Both `manifest.json` and `evaluation.json` record the refit
cutoff, rows, seasons, partial seasons, calibration strategy, and the
pre-refit metric statement.

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
- rejects missing, empty, or `NaT` game-start and feature-observation timestamps;
- requires `features_available_at <= game_start_at`;
- requires every row to be marked pregame safe;
- requires one stable row per game.

Timestamp ordering compares UTC instants, preserving explicit timezone offsets
across daylight saving transitions. A feature observed exactly at kickoff is
accepted; unknown observation time cannot establish pregame safety.

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

Composer automatically runs `scripts/install-ml-packages.sh --if-available`
during production installs. The installer refreshes both sports packages,
verifies imports, and runs `pip check`; machines without the shared ML runtime
skip it cleanly. Runtime inference also prepends the active release's package
source directory to `PYTHONPATH`.

NFL totals use a separate baseline-residual policy. XGBoost predicts a
correction to `feature_model_predicted_total`; chronological calibration picks
a correction weight, including zero, and the deployed adjustment is capped.
This policy does not change the win-probability or margin estimators.

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
docker build --tag picksports-nfl-ml:0.3.0 ml/nfl

docker run --rm \
  --volume "$PWD/storage/app/ml:/data:ro" \
  --volume "$PWD/ml/nfl/artifacts:/artifacts" \
  picksports-nfl-ml:0.3.0 \
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
  feature_schema.json
  preprocessor.json
  models/
    logistic_classifier.json
    xgboost_classifier.json
    xgboost_home_margin.json
    xgboost_total_points.json
  calibrators/
    logistic_regression_platt.json
    logistic_regression_isotonic.json
    xgboost_platt.json
    xgboost_isotonic.json
  tuning/
    optuna_provenance.json
  explanations/
    global_shap_importance.json
    shap_values.parquet
    xgboost_feature_importance.json
```

The manifest records the model run, artifact ID, code/config/dataset/schema
hashes, a package-source hash, dependency versions, seed, selected classifier,
blend weights, calibrators, evaluation training window, complete held-out
season, deployment-refit provenance, and a SHA-256 for every artifact file. A
dirty working tree is marked in `code_version`; the source hash remains the
exact identity of the Python training code.

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

### Artifact trust boundary

NFL tabular inference accepts only `picksports-nfl-tabular-numeric-v1`: fixed numeric preprocessing/logistic/calibration state and role-validated native XGBoost JSON. It does not load Python object serialization. Legacy joblib/UBJ runs have no fallback or automatic conversion; regenerate them under the reviewed numeric producer and repeat the normal challenger/shadow review. Training still uses the pinned scientific libraries; their transitive joblib dependency is not an artifact loader.

The run has exactly the 16 supported inventory members shown above, including the three explanation files and tuning provenance; SHAP Parquet is retained as nonexecuted evidence. Required components are at most 8 MiB, metadata/schema JSON at most 2 MiB, SHAP Parquet at most 64 MiB, and the whole run at most 128 MiB. JSON has fixed depth, structural and numeric-token limits, rejects duplicate keys/nonfinite literals, and is checked against the exact bytes/size consumed. Feature order is explicit and hashed separately; the schema JSON retains insertion order. Numeric shapes/roles, calibrator methods, residual-total semantics and manifest selection policy are checked before any native model constructor.

These are refusal boundaries, not maximum-volume or latency certification. Large/custom models outside the fixed formats require an explicit reviewed extension. Prediction JSON accepts one to 1000 feature objects under the metadata JSON limits; CSV/Parquet input remains an operator-side data-capacity concern. Neither format markers nor matching hashes authenticate a producer or establish calibration. Publish immutable directories and restrict writers. The PHP archive must pass the clean inner runtime directory; wrapper metadata and an optional exact dataset stay outside that directory.

Reported chronological evaluation metrics describe the pre-refit evaluation models. The saved deployment estimators are refit on later eligible settled rows; those historical holdout metrics do not validate the refit score distribution. Promotion requires separately reviewed shadow evidence and does not gain calibration or approval status from this integrity check. Requirements files pin listed distributions but do not constitute a fully hashed platform-specific transitive lock; do not describe the current container build as bit reproducible.
