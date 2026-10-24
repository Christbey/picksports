from __future__ import annotations

import json

import numpy as np
import pandas as pd
import pytest

from picksports_nfl_ml.props import FEATURES, benchmark, chronological_folds, run_benchmark, validate_samples


@pytest.fixture
def prop_frame() -> pd.DataFrame:
    rng = np.random.default_rng(42)
    rows = []
    for week in range(8):
        kickoff = pd.Timestamp('2026-08-02 17:00:00', tz='UTC') + pd.Timedelta(weeks=week)
        for player in range(12):
            mean = float(8 + player)
            row = {feature: mean for feature in FEATURES}
            row.update({"game_id": week * 2 + player % 2, "player_id": player, "market": "player_rush_attempts",
                        "game_start_at": kickoff.isoformat(),
                        "history_max_game_start_at": (kickoff - pd.Timedelta(days=7)).isoformat(),
                        "actual": float(max(0, mean + rng.normal())), "legacy_mean": mean,
                        "recent5_mean": mean, "quote_line": mean + .5,
                        "quote_observed_at": (kickoff - pd.Timedelta(hours=4)).isoformat()})
            rows.append(row)
    return pd.DataFrame(rows)


def test_folds_keep_games_together_and_use_only_mature_earlier_labels(prop_frame):
    frame = validate_samples(prop_frame)
    folds = list(chronological_folds(frame, 20))
    assert folds
    for _, train, test in folds:
        assert set(train.game_id).isdisjoint(set(test.game_id))
        assert train.game_start_at.max() + pd.Timedelta(hours=24) < test.game_start_at.min()


@pytest.mark.parametrize('bad', ['duplicate', 'future_history', 'missing_identity', 'infinite', 'unknown_feature', 'late_quote', 'immature_history'])
def test_rejects_unsafe_samples(prop_frame, bad):
    if bad == 'duplicate':
        prop_frame = pd.concat([prop_frame, prop_frame.iloc[[0]]])
    elif bad == 'future_history':
        prop_frame.loc[0, 'history_max_game_start_at'] = prop_frame.loc[0, 'game_start_at']
    elif bad == 'missing_identity':
        prop_frame.loc[0, 'player_id'] = None
    elif bad == 'infinite':
        prop_frame.loc[0, FEATURES[0]] = np.inf
    elif bad == 'unknown_feature':
        prop_frame['feature_actual_outcome'] = prop_frame.actual
    elif bad == 'immature_history':
        prop_frame.loc[0, 'history_max_game_start_at'] = '2026-08-02T12:00:00Z'
    elif bad == 'late_quote':
        prop_frame.loc[0, 'quote_observed_at'] = '2026-08-03T17:00:00Z'
    with pytest.raises(ValueError):
        validate_samples(prop_frame)


def test_all_models_score_identical_samples_without_automatic_promotion(prop_frame):
    result = benchmark(prop_frame, 20)
    market = result['markets']['player_rush_attempts']
    assert result['production_promotion'] is False
    assert market['evaluated_rows'] == sum(fold['test_rows'] for fold in market['folds'])
    assert set(market['models']) == {'recent5_mean', 'legacy_neutral', 'ridge', 'xgboost'}
    assert all(np.isfinite(metrics['mae']) for metrics in market['models'].values())
    assert market['quote_reference']['n'] == market['evaluated_rows']


def test_future_outcomes_do_not_change_earlier_fold_predictions(prop_frame):
    # Aggregate earlier rows alone versus a dataset with extreme later targets.
    import picksports_nfl_ml.props as module
    class RecordingRegressor:
        def __init__(self, **kwargs):
            self.mean = None
        def fit(self, features, actual):
            self.mean = actual.mean()
        def predict(self, features):
            return np.full(len(features), self.mean)
    # Earlier fold metrics must remain stable; inspect exact training calls too.
    calls = []
    class RecordingRidge(RecordingRegressor):
        def fit(self, features, actual):
            calls.append((features.copy(), actual.copy()))
            return super().fit(features, actual)
    from unittest.mock import patch
    with patch.object(module, 'make_pipeline', return_value=RecordingRidge()), patch.object(module, 'XGBRegressor', RecordingRegressor):
        initial = benchmark(prop_frame, 20)
        first_train = calls[0]
        calls.clear()
        prop_frame.loc[prop_frame.index[-12:], 'actual'] = 10000
        changed = benchmark(prop_frame, 20)
        assert initial['markets']['player_rush_attempts']['folds'][0]['models'] == changed['markets']['player_rush_attempts']['folds'][0]['models']
        pd.testing.assert_frame_equal(calls[0][0], first_train[0])
        pd.testing.assert_series_equal(calls[0][1], first_train[1])


def test_small_samples_are_reported_instead_of_fitted(prop_frame):
    result = benchmark(prop_frame.iloc[:12], 50)
    assert result['markets']['player_rush_attempts']['status'] == 'insufficient_training_history'


def test_report_preserves_input_and_checks_dataset_hash(prop_frame, tmp_path):
    source = tmp_path / 'samples.json'
    output = tmp_path / 'report.json'
    source.write_text(json.dumps({'sample_schema_version': 'nfl-prop-benchmark-v1', 'samples': prop_frame.to_dict('records')}))
    with pytest.raises(ValueError, match='SHA-256'):
        run_benchmark(source, output, expected_sha256='bad')
    with pytest.raises(ValueError, match='overwrite'):
        run_benchmark(source, source)
    report = run_benchmark(source, output, 20)
    assert json.loads(output.read_text())['dataset_sha256'] == report['dataset_sha256']
    assert json.loads(source.read_text())['sample_schema_version'] == 'nfl-prop-benchmark-v1'


def test_cli_writes_aggregate_benchmark_report(prop_frame, tmp_path, capsys):
    from picksports_nfl_ml.cli import main
    source = tmp_path / 'samples.json'
    destination = tmp_path / 'benchmark.json'
    source.write_text(json.dumps({'sample_schema_version': 'nfl-prop-benchmark-v1', 'samples': prop_frame.to_dict('records')}))
    main(['props-benchmark', '--input', str(source), '--output', str(destination), '--minimum-training-rows', '50'])
    payload = json.loads(capsys.readouterr().out)
    assert payload['comparison'] == 'nfl_prop_walk_forward_benchmark_v1'
    assert payload['production_promotion'] is False
    assert json.loads(destination.read_text())['implementation_sha256'] == payload['implementation_sha256']
