"""Freeze a numeric JSON bundle for PHP-only prospective prop shadow inference."""
from __future__ import annotations

import json
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd

from picksports_nfl_ml.hashing import sha256_file
from picksports_nfl_ml.props import (
    BOOSTED_PARAMETERS, COUNT_MARKETS, FEATURES, RIDGE_ALPHA, fit_estimators, validate_samples,
)

SHADOW_MARKETS = {
    "player_pass_attempts", "player_pass_completions", "player_pass_tds",
    "player_pass_yds", "player_reception_yds", "player_receptions", "player_rush_attempts",
}


def portable_models(estimators: dict[str, Any]) -> dict[str, Any]:
    ridge = estimators['ridge']
    imputer, scaler, regressor = ridge.steps[0][1], ridge.steps[1][1], ridge.steps[2][1]
    booster = estimators['xgboost'].get_booster()
    config = json.loads(booster.save_config())
    return {
        'ridge': {'imputation': imputer.statistics_.tolist(), 'mean': scaler.mean_.tolist(),
                  'scale': scaler.scale_.tolist(), 'coefficients': regressor.coef_.tolist(),
                  'intercept': float(regressor.intercept_)},
        'xgboost': {'base_score': float(config['learner']['learner_model_param']['base_score']),
                    'trees': [json.loads(tree) for tree in booster.get_dump(dump_format='json')]},
    }


def portable_predict(models: dict[str, Any], features: dict[str, float | None]) -> dict[str, float]:
    ridge = models['ridge']
    result = float(ridge['intercept'])
    for index, name in enumerate(FEATURES):
        value = features.get(name)
        value = ridge['imputation'][index] if value is None else value
        result += (value - ridge['mean'][index]) / ridge['scale'][index] * ridge['coefficients'][index]
    boosted = float(models['xgboost']['base_score'])
    for node in models['xgboost']['trees']:
        while 'leaf' not in node:
            value = features.get(node['split'])
            # XGBoost's native feature matrix is float32; preserve threshold semantics.
            branch = node['missing'] if value is None else node['yes'] if float(np.float32(value)) < float(np.float32(node['split_condition'])) else node['no']
            node = next(child for child in node['children'] if child['nodeid'] == branch)
        boosted += node['leaf']
    return {'ridge': result, 'xgboost': boosted}


def freeze(input_path: str | Path, output_path: str | Path, minimum_training_rows: int = 50,
           seed: int = 42, expected_sha256: str | None = None) -> dict[str, Any]:
    source = Path(input_path).expanduser().resolve()
    output = Path(output_path).expanduser().resolve()
    if source == output or output.exists():
        raise ValueError('Frozen bundle output must be a new file.')
    if minimum_training_rows < 2:
        raise ValueError('At least two training observations are required.')
    before = sha256_file(source)
    if expected_sha256 and before != expected_sha256.lower():
        raise ValueError('Dataset SHA-256 mismatch.')
    payload = json.loads(source.read_text())
    if payload.get('sample_schema_version') != 'nfl-prop-benchmark-v1':
        raise ValueError('Unsupported prop sample schema.')
    frame = validate_samples(pd.DataFrame(payload['samples']))
    frozen_at = pd.Timestamp(datetime.now(timezone.utc))
    frame = frame.loc[frame['game_start_at'] + pd.Timedelta(hours=24) < frozen_at]
    markets = {}
    for market, rows in frame.groupby('market', sort=True):
        if market not in SHADOW_MARKETS or len(rows) < minimum_training_rows:
            continue
        estimators = fit_estimators(rows, seed)
        models = portable_models(estimators)
        markets[market] = {'training_rows': len(rows), 'training_games': int(rows['game_id'].nunique()),
                           'training_max_kickoff': rows['game_start_at'].max().isoformat(),
                           'training_labels_available_at': (rows['game_start_at'].max() + pd.Timedelta(hours=24)).isoformat(),
                           'nonnegative': market in COUNT_MARKETS, **models}
    if not markets:
        raise ValueError('No shortlisted market has sufficient eligible training history.')
    if sha256_file(source) != before:
        raise ValueError('Dataset changed during training.')
    bundle = {'format': 'nfl-prop-shadow-bundle-v1', 'feature_version': 'nfl-prop-benchmark-v1',
              'frozen_at': frozen_at.isoformat(), 'dataset_sha256': before,
              'builder_sha256': sha256_file(Path(__file__)), 'benchmark_sha256': sha256_file(Path(__file__).with_name('props.py')),
              'features': FEATURES, 'markets': markets, 'seed': seed,
              'training_parameters': {'ridge_alpha': RIDGE_ALPHA, 'xgboost': BOOSTED_PARAMETERS},
              'production_promotion': False, 'probabilities': 'not_produced',
              'excluded_markets': {'player_anytime_td': 'Training count excludes return touchdowns; production settlement includes them.',
                                   'player_pass_interceptions': 'No retrospective MAE improvement.',
                                   'player_rush_yds': 'No retrospective MAE improvement.'}}
    output.parent.mkdir(parents=True, exist_ok=True)
    with output.open('x', encoding='utf-8') as handle:
        handle.write(json.dumps(bundle, sort_keys=True, allow_nan=False) + '\n')
    return {'path': str(output), 'sha256': sha256_file(output), 'markets': list(markets),
            'frozen_at': bundle['frozen_at'], 'dataset_sha256': before, 'production_promotion': False}
