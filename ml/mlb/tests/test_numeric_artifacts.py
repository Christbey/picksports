from __future__ import annotations

import copy
import json
import warnings

import numpy as np
import pandas as pd
import pytest
from sklearn.linear_model import LogisticRegression
from xgboost import XGBClassifier, XGBRegressor

from picksports_mlb_ml.calibration import fit_calibrator
from picksports_mlb_ml.models import build_preprocessor
from picksports_mlb_ml.numeric_artifacts import (
    FORMAT, calibrator_state, decode_json, load_calibrator, load_logistic,
    load_preprocessor, load_tree, logistic_state, preprocessor_state, read_json,
    tree_state, validate_tree_state, write_json,
)


def test_numeric_preprocessing_preserves_feature_order_missing_zero_and_constant_columns(tmp_path):
    frame = pd.DataFrame({'z': [0., 2., np.nan, 4.], 'a': [3., 3., 3., 3.], 'empty': [np.nan] * 4})
    fitted = build_preprocessor().fit(frame)
    path = tmp_path / 'preprocessor.json'
    write_json(path, preprocessor_state(fitted, list(frame.columns)))
    restored = load_preprocessor(read_json(path))
    probe = pd.DataFrame({'z': [np.nan, 0., -10., 12.], 'a': [3., np.nan, 0., 6.], 'empty': [np.nan, 0., 1., 2.]})
    np.testing.assert_allclose(restored.transform(probe), fitted.transform(probe), rtol=0, atol=0)
    with pytest.raises(ValueError, match='order'):
        restored.transform(probe[['a', 'z', 'empty']])


@pytest.mark.parametrize('classes,mode', [(2, 'auto'), (3, 'auto'), (3, 'ovr')])
def test_numeric_logistic_probabilities_match_fitted_estimators(classes, mode):
    rng = np.random.default_rng(19)
    values = rng.normal(size=(90, 3))
    labels = np.arange(90) % classes
    with warnings.catch_warnings():
        warnings.simplefilter('ignore', FutureWarning)
        fitted = LogisticRegression(multi_class=mode, random_state=19).fit(values, labels)
    restored = load_logistic(logistic_state(fitted))
    probe = np.vstack([values[:5], [-1000., 0., 1000.], [0., 0., 0.]])
    np.testing.assert_allclose(restored.predict_proba(probe), fitted.predict_proba(probe), rtol=1e-13, atol=4e-15)
    np.testing.assert_array_equal(np.round(restored.predict_proba(probe), 6), np.round(fitted.predict_proba(probe), 6))


@pytest.mark.parametrize('method', ['platt', 'isotonic'])
def test_numeric_calibration_matches_fitted_boundaries_and_extremes(method):
    probabilities = np.linspace(.001, .999, 80)
    labels = (probabilities > .45).astype(int)
    labels[[3, 20, 45, 60]] = 1 - labels[[3, 20, 45, 60]]
    fitted = fit_calibrator(method, probabilities, labels, 19)
    restored = load_calibrator(calibrator_state(fitted))
    probe = np.concatenate([[-1., 0., .000001], probabilities, [.999999, 1., 2.]])
    np.testing.assert_allclose(restored.predict(probe), fitted.predict(probe), rtol=1e-13, atol=4e-15)


@pytest.mark.parametrize('kind', ['xgboost_binary_classifier', 'xgboost_multiclass_classifier', 'xgboost_regressor'])
def test_bounded_native_tree_json_preserves_synthetic_predictions(kind):
    rng = np.random.default_rng(7)
    values = rng.normal(size=(90, 3))
    if kind == 'xgboost_regressor':
        fitted = XGBRegressor(n_estimators=4, max_depth=2, n_jobs=1).fit(values, values[:, 0] * 2)
    else:
        labels = np.arange(90) % (3 if kind == 'xgboost_multiclass_classifier' else 2)
        fitted = XGBClassifier(n_estimators=4, max_depth=2, n_jobs=1).fit(values, labels)
    restored = load_tree(tree_state(fitted, kind=kind, feature_count=3), expected_kind=kind, feature_count=3)
    method = 'predict' if kind == 'xgboost_regressor' else 'predict_proba'
    np.testing.assert_array_equal(getattr(restored, method)(values), getattr(fitted, method)(values))


@pytest.mark.parametrize('data,options', [
    (b'{"a":1,"a":2}', {}), (b'{"n":NaN}', {}), (b'{"n":1e999}', {}),
    (b'{"n":999999999999999999999999999}', {}),
    (b'[' * 25 + b'0' + b']' * 25, {}), (b'[0,0,0,0]', {'max_tokens': 2}),
    (b'{"a":1}', {'max_bytes': 2}), (b'\x80\x04harmless-pickle-header', {}),
])
def test_numeric_json_refuses_invalid_and_over_budget_data_before_loading(data, options):
    with pytest.raises(ValueError):
        decode_json(data, **options)


def test_numeric_json_refuses_symlink_and_exact_byte_growth(tmp_path):
    path = tmp_path / 'source.json'
    path.write_bytes(b'{"a":1}')
    link = tmp_path / 'link.json'
    link.symlink_to(path)
    with pytest.raises(ValueError, match='symlink'):
        read_json(link)
    with pytest.raises(ValueError, match='byte budget'):
        write_json(tmp_path / 'output.json', {'a': 1}, max_bytes=7)


@pytest.mark.parametrize('mutation', ['zero_scale', 'foreign_field', 'boolean', 'shape'])
def test_numeric_preprocessing_refuses_unrecognized_or_corrupt_states(mutation):
    state = {'format': FORMAT, 'kind': 'median_standard_scaler', 'feature_names': ['x'], 'medians': [0.], 'means': [0.], 'scales': [1.], 'sample_count': 4}
    if mutation == 'zero_scale': state['scales'] = [0.]
    elif mutation == 'foreign_field': state['module'] = 'untrusted'
    elif mutation == 'boolean': state['medians'] = [True]
    else: state['means'] = [0., 1.]
    with pytest.raises(ValueError): load_preprocessor(state)


@pytest.mark.parametrize('mutation', ['huge_features', 'huge_nodes', 'wrong_objective', 'cycle', 'unknown_attribute', 'boolean_version'])
def test_corrupt_tree_metadata_refuses_before_native_loader(mutation, monkeypatch):
    values = np.arange(30).reshape(15, 2)
    fitted = XGBClassifier(n_estimators=1, max_depth=1, n_jobs=1).fit(values, np.arange(15) % 2)
    state = tree_state(fitted, kind='xgboost_binary_classifier', feature_count=2)
    model = state['model']; learner = model['learner']; tree = learner['gradient_booster']['model']['trees'][0]
    if mutation == 'huge_features': learner['learner_model_param']['num_feature'] = '999999999'
    elif mutation == 'huge_nodes': tree['tree_param']['num_nodes'] = '999999999'
    elif mutation == 'wrong_objective': learner['objective']['name'] = 'foreign:objective'
    elif mutation == 'cycle': tree['left_children'][0] = tree['right_children'][0] = 0
    elif mutation == 'unknown_attribute': learner['attributes']['scikit_learn'] = '{"module":"untrusted"}'
    else: model['version'][1] = True
    monkeypatch.setattr(XGBClassifier, 'load_model', lambda *args, **kwargs: pytest.fail('Native parser was reached'))
    with pytest.raises(ValueError): load_tree(state, expected_kind='xgboost_binary_classifier', feature_count=2)


@pytest.mark.parametrize('method', ['platt', 'isotonic'])
def test_numeric_multiclass_calibration_preserves_tie_order_and_normalization(method):
    from picksports_mlb_ml.periods import MulticlassCalibrator
    from picksports_mlb_ml.numeric_artifacts import multiclass_calibrator_state, load_multiclass_calibrator
    rng = np.random.default_rng(12)
    raw = rng.dirichlet([2., 1., 2.], size=90)
    labels = np.arange(90) % 3
    fitted = MulticlassCalibrator(method=method,
        calibrators={label: fit_calibrator(method, raw[:, label], (labels == label).astype(int), 12) for label in (0, 1, 2)},
        probability_floor=.002)
    restored = load_multiclass_calibrator(multiclass_calibrator_state(fitted))
    probe = np.vstack([raw[:5], [0., 1., 0.], [1., 0., 0.], [0., 0., 1.]])
    np.testing.assert_allclose(restored.predict(probe), fitted.predict(probe), rtol=1e-13, atol=4e-15)
    np.testing.assert_array_equal(np.round(restored.predict(probe), 6), np.round(fitted.predict(probe), 6))
