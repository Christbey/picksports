from __future__ import annotations

import json

import numpy as np
import pandas as pd
import pytest

from picksports_nfl_ml.prop_shadow import freeze, portable_models, portable_predict
from picksports_nfl_ml.props import FEATURES, fit_estimators


def shadow_frame():
    rng = np.random.default_rng(42)
    rows = []
    for i in range(80):
        kickoff = pd.Timestamp('2026-08-01T17:00:00Z') + pd.Timedelta(days=i // 4)
        row = {name: float(rng.normal(10, 3)) for name in FEATURES}
        row.update({'game_id': i, 'player_id': i % 4, 'market': 'player_rush_attempts',
                    'game_start_at': kickoff.isoformat(), 'history_max_game_start_at': (kickoff - pd.Timedelta(days=7)).isoformat(),
                    'actual': float(max(0, row['feature_recent5_mean'] + rng.normal())), 'legacy_mean': 10.0,
                    'recent5_mean': 10.0, 'quote_line': 10.5, 'quote_observed_at': (kickoff - pd.Timedelta(hours=1)).isoformat()})
        rows.append(row)
    return pd.DataFrame(rows)


def test_portable_json_matches_fitted_estimators_with_missing_inputs():
    frame = shadow_frame()
    frame.loc[::3, 'feature_opponent_mean'] = np.nan
    estimators = fit_estimators(frame, 42)
    portable = portable_models(estimators)
    for index in [0, 1, 2, 3, 10, 50]:
        features = {name: None if pd.isna(frame.loc[index, name]) else float(frame.loc[index, name]) for name in FEATURES}
        result = portable_predict(portable, features)
        input_frame = frame.loc[[index], FEATURES]
        assert result['ridge'] == pytest.approx(estimators['ridge'].predict(input_frame)[0], abs=1e-9)
        assert result['xgboost'] == pytest.approx(float(estimators['xgboost'].predict(input_frame)[0]), abs=1e-4)


def test_freeze_is_append_only_hashes_its_dataset_and_does_not_enable_production(tmp_path):
    source = tmp_path / 'samples.json'
    destination = tmp_path / 'frozen.json'
    source.write_text(json.dumps({'sample_schema_version': 'nfl-prop-benchmark-v1', 'samples': shadow_frame().to_dict('records')}))
    result = freeze(source, destination)
    bundle = json.loads(destination.read_text())
    assert result['production_promotion'] is False
    assert list(bundle['markets']) == ['player_rush_attempts']
    assert 'player_anytime_td' in bundle['excluded_markets']
    assert len(result['sha256']) == 64
    assert len(bundle['markets']['player_rush_attempts']['xgboost']['trees']) == 80
    assert pd.Timestamp(bundle['markets']['player_rush_attempts']['training_labels_available_at']) < pd.Timestamp(bundle['frozen_at'])
    with pytest.raises(ValueError, match='new file'):
        freeze(source, destination)
    with pytest.raises(ValueError, match='SHA-256'):
        freeze(source, tmp_path / 'other.json', expected_sha256='wrong')
