# NFL ML On DigitalOcean

This is the production path for training and serving Picksports NFL models.
The prediction models are logistic regression and XGBoost. An OpenAI model is
not required for training or inference; an LLM may summarize explanations, but
it must not calculate probabilities or decide whether to bet.

## Runtime Layout

- Laravel application Droplet: feature snapshots, inference, shadow outputs,
  bet decisions, settlements, and the admin monitor.
- CPU training job: validation, Optuna trials, walk-forward evaluation,
  calibration, SHAP explanations, and artifact packaging.
- Private DigitalOcean Space: immutable datasets, model bundles, evaluation
  reports, and their SHA-256 hashes.
- MySQL: model runs, artifact registry, feature lineage, shadow observations,
  decisions, settlements, and signal grades.

The current application Droplet can run inference and constrained smoke
training. Full tuning should run during a quiet window with explicit CPU and
memory limits, or on a separate temporary CPU Droplet when trial volume grows.

## Application Environment

```dotenv
ML_FILESYSTEM_DISK=ml-spaces
ML_STORAGE_PREFIX=ml
ML_CACHE_DISK=ml-cache
ML_SPACES_ACCESS_KEY_ID=
ML_SPACES_SECRET_ACCESS_KEY=
ML_SPACES_REGION=
ML_SPACES_BUCKET=
ML_SPACES_ENDPOINT=
ML_SPACES_URL=
ML_SPACES_USE_PATH_STYLE_ENDPOINT=false

NFL_ML_SHADOW_ENABLED=false
NFL_ML_SHADOW_ARTIFACT_ID=
NFL_ML_PYTHON_BINARY=/home/forge/picksports-ml/.venv/bin/python
NFL_ML_PYTHON_MODULE=picksports_nfl_ml
NFL_ML_INFERENCE_TIMEOUT=30
NFL_ML_SHADOW_MAX_UNCERTAINTY=

ML_PROMOTION_MINIMUM_WINDOWS=3
ML_PROMOTION_MINIMUM_BETTER_WINDOW_RATE=0.60
ML_PROMOTION_MAX_BRIER_REGRESSION=0.02
ML_PROMOTION_MAX_LOG_LOSS_REGRESSION=0.10
ML_PROMOTION_MAX_MAE_REGRESSION=1.00
ML_PROMOTION_MINIMUM_LIVE_SHADOW_OBSERVATIONS=25
ML_PROMOTION_MINIMUM_SETTLED_SHADOW_DECISIONS=10
```

The Space must remain private. Laravel materializes an object into its local ML
cache only after its stored SHA-256 matches the registry.

## Build The Training Runtime

Use Python 3.11 or 3.12:

```bash
cd /home/forge/picksports-ml/ml/nfl
python3 -m venv /home/forge/picksports-ml/.venv
source /home/forge/picksports-ml/.venv/bin/activate
pip install --requirement requirements.lock.txt
ML_PYTHON_BINARY=/home/forge/picksports-ml/.venv/bin/python \
  /home/forge/picksports.app/current/scripts/install-ml-packages.sh
picksports-nfl-ml --help
```

The Composer `post-autoload-dump` hook runs this installer automatically during
normal Forge deployments when the shared virtual environment exists. The
command remains available for manual recovery. It installs both the MLB and NFL
packages from that exact release, verifies both imports, and runs `pip check`.
Laravel also prepends the active release's package source directory to
`PYTHONPATH` during inference, so a stale site-packages copy cannot silently
serve older code.

Use the repository post-deployment command as the final Forge deployment step:

```bash
cd /home/forge/picksports.app/current
PHP_BINARY=php8.4 bash scripts/post-deploy.sh
```

It migrates the database, verifies both release-matched Python packages, builds
Laravel's config/event/route/view caches, and restarts queue workers. Do not run
`optimize:clear` after this step unless the release is being repaired; doing so
leaves production requests on the uncached framework bootstrap path.

If Ubuntu does not provide `ensurepip` and the deploy user cannot install
`python3.12-venv`, create the same user-owned environment without sudo:

```bash
python3 -m pip install --user --break-system-packages virtualenv==20.28.0
python3 -m virtualenv /home/forge/picksports-ml/.venv
```

Docker is also supported:

```bash
docker build --tag picksports-nfl-ml:0.2.0 ml/nfl
docker run --rm --cpus=1.5 --memory=1g \
  --volume /home/forge/picksports-ml/data:/data:ro \
  --volume /home/forge/picksports-ml/artifacts:/artifacts \
  picksports-nfl-ml:0.2.0 \
  validate --input /data/nfl_training.csv
```

## Dataset And Training

Laravel owns historical reconstruction and the immutable export:

```bash
php artisan nfl:backfill-historical-predictions \
  --from-season=2017 \
  --to-season=2025 \
  --season-type=2 \
  --profile=full-historical

php artisan nfl:export-training-data \
  --profile=full-historical \
  --from-season=2017 \
  --to-season=2025 \
  --feature-version=nfl-pregame-ml-v3 \
  --path=storage/app/ml/nfl_full_historical_training_data_v3.csv
```

The Python runtime validates the dataset hash and runs complete-season
walk-forward evaluation:

```bash
picksports-nfl-ml validate \
  --input /data/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml

picksports-nfl-ml train \
  --input /data/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml \
  --output-dir /artifacts

picksports-nfl-ml diagnose \
  --input /data/nfl_full_historical_training_data_v3.csv \
  --schema config/feature_schema_v3.yaml \
  --output /artifacts/nfl-2024-diagnostic.json \
  --target-season 2024 \
  --training-windows expanding,4,5,6
```

Register the completed run from Laravel:

```bash
php artisan nfl:register-tabular-model-run /artifacts/<model-run-id> \
  --dataset=/data/nfl_full_historical_training_data_v3.csv

php artisan sports:evaluate-model-promotion <artifact-uuid>
```

Do not add `--promote` until the challenger beats the configured baseline in
at least three chronological windows and has enough live shadow evidence.

## Live Loop

1. Pregame generation writes an immutable feature snapshot.
2. The registered Python challenger returns probabilities, point estimates,
   uncertainty, and model lineage.
3. Laravel stores the result as a shadow output and keeps the public output on
   the baseline.
4. The bet-decision layer applies price, edge, uncertainty, risk, and bankroll
   gates.
5. Settlements write ROI, CLV, calibration, and error measurements.
6. Signal grading evaluates every reason code, risk flag, rule, and validated
   combination.
7. New challenger runs consume only stable targets and pre-kickoff features.

Enable a registered challenger only after its artifact ID is known:

```dotenv
NFL_ML_SHADOW_ENABLED=true
NFL_ML_SHADOW_ARTIFACT_ID=<artifact-uuid>
```

Promotion changes eligibility inside the decision layer. It does not directly
publish a recommendation.

## First Validated Run

The first complete DigitalOcean challenger was trained on July 26, 2026:

- Python run: `nfl-do-20260726-v3-003`
- Artifact: `edb3ef97-b11b-434e-b067-dd13dd331f3d`
- Dataset SHA-256: `4a7fec81cf839c453c37213a86d5e04e2719bbce6149db3028fa2165ee1064a4`
- Feature schema SHA-256: `7c344f76cfad8f7f3c1d2306eda196afa104855a4a40fd42cc3a8b666e45546e`
- Config SHA-256: `787bfeb805e93a0d8ea0c371e2e08c8784afc821ffe7000f11d7f0b41ade4ed0`
- Source SHA-256: `069753097aefb8108ec50113c7183906c9dee82bee0ab98582b2b91c488f63c1`
- Held-out season: 2025
- Held-out XGBoost: 63.84% accuracy, 0.23238 Brier, 0.65701 log loss
- Current Picksports baseline: 62.73% accuracy, 0.24192 Brier, 0.68776 log loss
- Walk-forward: challenger won 2 of 3 windows
- Average lift versus Picksports: -0.00256 Brier and -0.04389 log loss

Decision: retain the current public pipeline. The challenger remains registered
with `challenger` status because its positive 2025 result did not overcome the
negative average scoring-rule lift across all chronological windows. It must
not be promoted until a later run passes the offline gate and then accumulates
enough live shadow evidence.

The first live shadow observation was recorded for game `1668`:

- Baseline home-win probability: 0.495000
- Challenger home-win probability: 0.546411
- Output delta: +0.051411
- Active source: baseline
- Decision: `shadow_no_bet`
- Public: false
- Reasons: artifact not promoted; pregame market quote missing

This confirms that inference reaches the decision layer without changing the
public recommendation and that the no-bet explanation is persisted.

## 2024 Stability Remediation

The July 28, 2026 diagnostic reproduced the 2024 regression and identified two
specific causes:

- Isotonic calibration gained only `0.0013` validation Brier over Platt while
  worsening validation log loss by `0.0876`.
- Expanding-history XGBoost was allowed to dominate the challenger even though
  recent-season relationships had changed.

The v2 package now:

- rejects a calibrator when its validation log loss regresses by more than
  `0.01` versus the uncalibrated model;
- trains on the four most recent complete seasons;
- selects a logistic/XGBoost/Picksports blend chronologically;
- limits the combined challenger weight to `0.50`;
- emits cross-season feature-group ablations and drift diagnostics;
- evaluates win probability, margin, and total as separate promotion markets.

The first fully packaged v2 run is:

- Python run: `nfl-do-20260728-v5-002`
- Artifact: `223b7233-5928-4d8c-a5cc-8113a8aa63bd`
- Model version: `nfl-tabular-v2`
- Blend version: `baseline-anchored-v1`
- Dataset SHA-256: `4a7fec81cf839c453c37213a86d5e04e2719bbce6149db3028fa2165ee1064a4`
- Feature schema SHA-256: `dacdee5fe462bc389fa0dcaff3e578cde66c0dce80efeedd58e6b8f9daec7f3a`
- Config SHA-256: `8c245b17eaa28bbc79695ca1737dadb7431e91f08935ed6291b16e9c73fd5832`
- Source SHA-256: `e2d0e863eb302afa905ac4eb8d7b597ad41957374a52760b5420e83fc80aa855`

Walk-forward results versus Picksports:

| Season | Blend Brier | Picksports Brier | Blend log loss | Picksports log loss |
| --- | ---: | ---: | ---: | ---: |
| 2023 | 0.23832 | 0.24779 | 0.66980 | 0.69537 |
| 2024 | 0.21372 | 0.20810 | 0.61729 | 0.60361 |
| 2025 | 0.23803 | 0.24192 | 0.67525 | 0.68776 |

The challenger wins two of three seasons. Average improvement is now positive:
`+0.00258` Brier and `+0.00814` log loss, using the canonical
`baseline - challenger` convention. The 2024 regression is reduced to
`0.00562` Brier and `0.01367` log loss, safely below the explicit worst-window
ceilings.

Win probability and spread are offline eligible. The v2 totals model remained
blocked because its direct total-points regressor did not beat Picksports
consistently. NFL tabular v3 replaces only that branch with a baseline-residual
model: XGBoost learns corrections to the existing Picksports total, a
chronological calibration season selects a correction weight from zero through
`0.35`, and each correction is capped at four points. The model keeps the
Picksports total unchanged unless calibration MAE improves by at least `0.10`.
Win-probability and spread behavior are unchanged. The artifact is
`promotion_eligible`, not promoted: production promotion still requires at
least 25 live pregame-safe observations and 10 settled shadow decisions for
each requested market.
