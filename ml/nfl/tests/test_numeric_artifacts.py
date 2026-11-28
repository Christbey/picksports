from __future__ import annotations

import copy
import json
import uuid
from pathlib import Path

import numpy as np
import pandas as pd
import pytest
from xgboost import XGBClassifier, XGBRegressor

from picksports_nfl_ml.artifacts import save_run_artifacts, verify_artifact_inventory
from picksports_nfl_ml.calibration import fit_calibrator
from picksports_nfl_ml.hashing import sha256_file, sha256_json
from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.models import fit_model_set
from picksports_nfl_ml.numeric_artifacts import (
    TABULAR_FORMAT, MAX_COMPONENT_BYTES, decode_json, read_json,
    preprocessor_state, load_preprocessor, logistic_state, load_logistic,
    calibrator_state, load_calibrator, tree_state, load_tree,
)
from picksports_nfl_ml.schema import FeatureSchema


@pytest.fixture
def fitted(synthetic_frame, schema_path):
    raw = copy.deepcopy(FeatureSchema.load(schema_path).raw)
    raw['features']['feature_all_missing'] = {'type': 'float', 'nullable': True}
    raw['features']['feature_constant'] = {'type': 'float', 'nullable': True}
    schema = FeatureSchema.from_mapping(schema_path, raw)
    frame = synthetic_frame.copy()
    frame['feature_all_missing'] = np.nan
    frame['feature_constant'] = 7.0
    frame.loc[::3, 'feature_market_total'] = np.nan
    return fit_model_set(frame, schema, 42), frame, schema


def test_numeric_preprocessing_keeps_exact_order_empty_constant_and_missing_columns(fitted):
    models, frame, schema = fitted
    portable = load_preprocessor(preprocessor_state(models.preprocessor, schema.feature_names))
    expected = models.preprocessor.transform(frame[schema.feature_names])
    np.testing.assert_array_equal(portable.transform(frame[schema.feature_names]), expected)
    assert portable.sample_count == len(frame)
    with pytest.raises(ValueError, match='order'):
        portable.transform(frame[list(reversed(schema.feature_names))])


def test_numeric_binary_logistic_preserves_fitted_probabilities_and_saturation(fitted):
    models, frame, schema = fitted
    transformed = models.preprocessor.transform(frame[schema.feature_names])
    transformed = np.vstack([transformed, np.zeros(transformed.shape[1]), np.full(transformed.shape[1], 1e6)])
    portable = load_logistic(logistic_state(models.logistic_classifier))
    np.testing.assert_allclose(portable.predict_proba(transformed), models.logistic_classifier.predict_proba(transformed), atol=1e-14, rtol=1e-14)


@pytest.mark.parametrize('method', ['platt', 'isotonic'])
def test_numeric_calibrator_preserves_clipping_knots_and_endpoint_math(method):
    probabilities = np.linspace(.01, .99, 40)
    labels = np.array([0, 1] * 20)
    original = fit_calibrator(method, probabilities, labels, 42)
    values = np.concatenate([np.array([-10, 0, 1e-6, .5, 1-1e-6, 1, 10]), probabilities])
    np.testing.assert_allclose(load_calibrator(calibrator_state(original)).predict(values), original.predict(values), atol=1e-14, rtol=1e-14)


def test_numeric_isotonic_preserves_a_single_threshold_constant_model():
    original = fit_calibrator('isotonic', np.full(4, .5), np.array([0, 1, 0, 1]), 42)
    np.testing.assert_array_equal(load_calibrator(calibrator_state(original)).predict(np.array([0, .5, 1])), original.predict(np.array([0, .5, 1])))


@pytest.mark.parametrize('kind', ['xgboost_home_win_classifier', 'xgboost_home_margin', 'xgboost_total_residual'])
def test_numeric_native_tree_roles_preserve_exact_fitted_outputs(fitted, kind):
    models, frame, schema = fitted
    original = {'xgboost_home_win_classifier': models.xgboost_classifier, 'xgboost_home_margin': models.margin_regressor, 'xgboost_total_residual': models.total_regressor}[kind]
    transformed = models.preprocessor.transform(frame[schema.feature_names])
    restored = load_tree(tree_state(original, kind=kind, feature_count=len(schema.feature_names)), expected_kind=kind, feature_count=len(schema.feature_names))
    method = 'predict_proba' if kind == 'xgboost_home_win_classifier' else 'predict'
    np.testing.assert_array_equal(getattr(restored, method)(transformed), getattr(original, method)(transformed))


@pytest.fixture
def numeric_run(tmp_path, fitted):
    models, frame, schema = fitted
    transformed = models.preprocessor.transform(frame[schema.feature_names])
    labels = frame[schema.target_columns['home_win']].to_numpy(dtype=int)
    calibrators = {name: {method: fit_calibrator(method, estimator.predict_proba(transformed)[:, 1], labels, 42) for method in ['platt', 'isotonic']} for name, estimator in [('logistic_regression', models.logistic_classifier), ('xgboost', models.xgboost_classifier)]}
    manifest = {'model_run_id': 'numeric-fixture', 'artifact_id': str(uuid.uuid4()), 'model_type': 'nfl_tabular_bundle',
                'model_version': 'nfl-tabular-v3', 'blend_version': 'baseline-anchored-v2', 'feature_schema_version': schema.version,
                'dataset_hash': 'a' * 64, 'feature_schema_hash': schema.hash, 'config_hash': 'b' * 64,
                'champion_classifier': 'blend', 'selected_calibrators': {'logistic_regression': 'platt', 'xgboost': 'isotonic'},
                'classifier_blend': {'weights': {'logistic_regression': .2, 'xgboost': .3, 'current_picksports': .5}, 'probability_floor': .01, 'max_challenger_weight': .5},
                'total_model': {'strategy': 'baseline_residual_blend', 'baseline_feature': 'feature_model_predicted_total', 'baseline_fallback': models.total_baseline_fallback, 'selected_residual_weight': .25, 'max_residual_weight': .35, 'max_abs_adjustment': 4.0},
                'residual_standard_deviation': {'home_margin': 10.0, 'total_points': 8.0}}
    # Serialization fixture only; no chronological evaluation/promotion claim is attached.
    run = save_run_artifacts(tmp_path / 'runs', manifest, {}, {}, models, calibrators, schema, {}, {}, pd.DataFrame({'value': []}), {})
    return run, frame, schema


def reseal(run, name, payload):
    path = run / name
    path.write_text(json.dumps(payload))
    manifest = json.loads((run / 'manifest.json').read_text())
    manifest['artifacts'][name] = {'bytes': path.stat().st_size, 'sha256': sha256_file(path)}
    (run / 'manifest.json').write_text(json.dumps(manifest))


def native_trap(monkeypatch):
    def forbidden(*args, **kwargs):
        raise AssertionError('Native model constructor reached before complete admission')
    monkeypatch.setattr(XGBClassifier, '__init__', forbidden)
    monkeypatch.setattr(XGBRegressor, '__init__', forbidden)


@pytest.mark.parametrize('field,value', [
    ('champion_classifier', 'unknown'), ('artifact_id', 'not-a-uuid'), ('model_run_id', '../escape'),
    ('dataset_hash', 'bad'), ('selected_calibrators', {'logistic_regression': 'unknown', 'xgboost': 'platt'}),
    ('classifier_blend', {'weights': {'logistic_regression': .5, 'xgboost': .5, 'current_picksports': .5}, 'probability_floor': .01, 'max_challenger_weight': .5}),
    ('residual_standard_deviation', {'home_margin': 0, 'total_points': 8}),
    ('residual_standard_deviation', {'home_margin': True, 'total_points': 8}),
])
def test_manifest_policy_refuses_before_any_native_constructor(numeric_run, monkeypatch, field, value):
    run, _, _ = numeric_run
    manifest = json.loads((run / 'manifest.json').read_text())
    manifest[field] = value
    (run / 'manifest.json').write_text(json.dumps(manifest))
    native_trap(monkeypatch)
    with pytest.raises(ValueError):
        InferenceBundle.load(run)


@pytest.mark.parametrize('field,value', [('selected_residual_weight', -1), ('max_abs_adjustment', -1), ('baseline_fallback', True), ('strategy', 'direct_total')])
def test_total_residual_contract_cannot_be_reinterpreted_or_coerced(numeric_run, monkeypatch, field, value):
    run, _, _ = numeric_run
    manifest = json.loads((run / 'manifest.json').read_text())
    manifest['total_model'][field] = value
    (run / 'manifest.json').write_text(json.dumps(manifest))
    native_trap(monkeypatch)
    with pytest.raises(ValueError):
        InferenceBundle.load(run)


def test_actual_calibrator_method_must_match_its_filename(numeric_run, monkeypatch):
    run, _, _ = numeric_run
    reseal(run, 'calibrators/logistic_regression_platt.json', json.loads((run / 'calibrators/logistic_regression_isotonic.json').read_text()))
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='fixed role'):
        InferenceBundle.load(run)


def test_total_and_margin_native_states_cannot_be_swapped(numeric_run, monkeypatch):
    run, _, _ = numeric_run
    reseal(run, 'models/xgboost_total_points.json', json.loads((run / 'models/xgboost_home_margin.json').read_text()))
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='format'):
        InferenceBundle.load(run)


def test_ordered_schema_cannot_change_behind_an_equal_canonical_content_hash(numeric_run, monkeypatch):
    run, _, _ = numeric_run
    schema = json.loads((run / 'feature_schema.json').read_text())
    before = sha256_json(schema)
    schema['features'] = dict(reversed(list(schema['features'].items())))
    assert sha256_json(schema) == before
    reseal(run, 'feature_schema.json', schema)
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='Ordered'):
        InferenceBundle.load(run)


@pytest.mark.parametrize('name', ['models/logistic_classifier.json', 'feature_schema.json'])
def test_consumed_bytes_still_match_inventory_after_verification(numeric_run, monkeypatch, name):
    run, _, _ = numeric_run
    import picksports_nfl_ml.inference as inference
    original = inference.verify_artifact_inventory
    def swapped(root, manifest):
        original(root, manifest)
        path = root / name
        data = bytearray(path.read_bytes())
        index = next(i for i, value in enumerate(data) if 48 <= value <= 57)
        data[index] = 49 if data[index] != 49 else 50
        path.write_bytes(data)
    monkeypatch.setattr(inference, 'verify_artifact_inventory', swapped)
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='hash'):
        InferenceBundle.load(run)


def test_exact_numeric_inventory_refuses_executable_extras(numeric_run):
    run, _, _ = numeric_run
    extra = run / 'extra.joblib'
    extra.write_bytes(b'synthetic, never deserialized')
    manifest = json.loads((run / 'manifest.json').read_text())
    manifest['artifacts']['extra.joblib'] = {'bytes': extra.stat().st_size, 'sha256': sha256_file(extra)}
    with pytest.raises(ValueError, match='exact'):
        verify_artifact_inventory(run, manifest)


@pytest.mark.parametrize('raw', [b'{"x":1,"x":2}', b'{"x":NaN}', b'{"x":1e9999999}', b'{"x":' + b'9'*200 + b'}', b'['*25 + b'0' + b']'*25])
def test_numeric_json_refuses_duplicate_nonfinite_huge_and_deep_values(raw):
    with pytest.raises(ValueError):
        decode_json(raw)


def test_numeric_json_preflights_dense_payload_before_json_materialization(monkeypatch):
    import picksports_nfl_ml.numeric_artifacts as numeric
    def forbidden(*args, **kwargs):
        raise AssertionError('JSON materialization reached before structural refusal')
    monkeypatch.setattr(numeric.json, 'loads', forbidden)
    with pytest.raises(ValueError, match='structural'):
        numeric.decode_json(b'[' + b'0,' * 1_000_001 + b'0]')


def test_numeric_json_rejects_oversized_file_before_read_or_parse(tmp_path, monkeypatch):
    path = tmp_path / 'oversized.json'
    with path.open('wb') as stream:
        stream.truncate(MAX_COMPONENT_BYTES + 1)
    import picksports_nfl_ml.numeric_artifacts as numeric
    def forbidden(*args, **kwargs):
        raise AssertionError('Oversized component was opened')
    monkeypatch.setattr(numeric.os, 'open', forbidden)
    with pytest.raises(ValueError, match='byte budget'):
        read_json(path)

@pytest.mark.parametrize('mutation', ['features', 'groups', 'cycle', 'node_budget', 'attributes'])
def test_malformed_native_states_refuse_before_constructor(fitted, monkeypatch, mutation):
    models, _, schema = fitted
    state = tree_state(models.xgboost_classifier, kind='xgboost_home_win_classifier', feature_count=len(schema.feature_names))
    learner = state['model']['learner']
    trees = learner['gradient_booster']['model']
    if mutation == 'features':
        learner['learner_model_param']['num_feature'] = '513'
    elif mutation == 'groups':
        trees['tree_info'][0] = 1
    elif mutation == 'cycle':
        trees['trees'][0]['left_children'][0] = 0
    elif mutation == 'node_budget':
        trees['trees'][0]['tree_param']['num_nodes'] = '100001'
    else:
        learner['attributes']['python_class'] = 'not an executable role'
    native_trap(monkeypatch)
    with pytest.raises(ValueError):
        load_tree(state, expected_kind='xgboost_home_win_classifier', feature_count=len(schema.feature_names))


@pytest.mark.parametrize('mutation', ['boolean_median', 'zero_scale', 'negative_scale', 'boolean_count', 'duplicate_name'])
def test_numeric_preprocessing_refuses_incompatible_shapes_types_and_scale(fitted, mutation):
    models, _, schema = fitted
    state = preprocessor_state(models.preprocessor, schema.feature_names)
    if mutation == 'boolean_median':
        state['medians'][0] = True
    elif mutation == 'zero_scale':
        state['scales'][0] = 0
    elif mutation == 'negative_scale':
        state['scales'][0] = -1
    elif mutation == 'boolean_count':
        state['sample_count'] = True
    else:
        state['feature_names'][1] = state['feature_names'][0]
    with pytest.raises(ValueError):
        load_preprocessor(state)


def test_manifest_feature_version_matches_the_consumed_schema(numeric_run, monkeypatch):
    run, _, _ = numeric_run
    manifest = json.loads((run / 'manifest.json').read_text())
    manifest['feature_schema_version'] = 'different-schema-version'
    (run / 'manifest.json').write_text(json.dumps(manifest))
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='schema'):
        InferenceBundle.load(run)


@pytest.mark.parametrize('base', ['0', '1', '-1'])
def test_binary_native_base_score_is_admitted_before_any_constructor(fitted, monkeypatch, base):
    models, _, schema = fitted
    state = tree_state(models.xgboost_classifier, kind='xgboost_home_win_classifier', feature_count=len(schema.feature_names))
    state['model']['learner']['learner_model_param']['base_score'] = base
    native_trap(monkeypatch)
    with pytest.raises(ValueError):
        load_tree(state, expected_kind='xgboost_home_win_classifier', feature_count=len(schema.feature_names))


def test_fresh_decoder_budget_refusal_has_an_absolute_peak_below_128_mib(tmp_path):
    import os
    import subprocess
    import sys
    script = r'''
import json, resource, sys
from pathlib import Path
import picksports_nfl_ml.numeric_artifacts as numeric

def forbidden(*args, **kwargs):
    raise AssertionError('Dense JSON reached materialization')
numeric.json.loads = forbidden
refusals = 0
try:
    numeric.decode_json(b'[' + b'{},' * 600000 + b'{}]')
except ValueError:
    refusals += 1
path = Path(sys.argv[1]) / 'oversized.json'
with path.open('wb') as stream:
    stream.truncate(numeric.MAX_COMPONENT_BYTES + 1)
try:
    numeric.read_json(path)
except ValueError:
    refusals += 1
peak = resource.getrusage(resource.RUSAGE_SELF).ru_maxrss
lifetime_peak = peak if sys.platform == 'darwin' else peak * 1024
if sys.platform == 'linux':
    # Linux ru_maxrss may retain the parent's pre-exec high-water mark.
    # VmHWM reports the current process image's peak; kernel RSS accounting
    # is approximate, so this is a reported-peak regression, not a hard RSS cap.
    status = Path('/proc/self/status').read_text()
    if len(status) > 65536:
        raise ValueError('Unexpected process status size')
    rows = [line.split() for line in status.splitlines() if line.startswith('VmHWM:')]
    if len(rows) != 1 or len(rows[0]) != 3 or rows[0][0] != 'VmHWM:' or rows[0][2] != 'kB':
        raise ValueError('Missing or malformed current-image peak RSS')
    value = rows[0][1]
    if not value.isascii() or not value.isdigit() or len(value) > 20 or int(value) <= 0:
        raise ValueError('Invalid current-image peak RSS units/value')
    peak = int(value) * 1024
    measurement = 'linux_proc_self_status_VmHWM_reported_current_image'
else:
    peak = lifetime_peak
    measurement = 'darwin_native_ru_maxrss_bytes' if sys.platform == 'darwin' else 'native_ru_maxrss_kib'
print(json.dumps({'refusals': refusals, 'peak_bytes': peak, 'peak_measurement': measurement, 'lifetime_ru_maxrss_bytes': lifetime_peak}))
'''
    environment = {name: os.environ[name] for name in ['PATH', 'HOME', 'USER', 'TMPDIR', 'LANG', 'LC_ALL'] if name in os.environ}
    environment.update(PYTHONPATH=str(Path(__file__).parents[1] / 'src'), PYTHONDONTWRITEBYTECODE='1', PYTHONNOUSERSITE='1', OPENBLAS_NUM_THREADS='1', OMP_NUM_THREADS='1')
    result = subprocess.run([sys.executable, '-c', script, str(tmp_path)], env=environment, capture_output=True, text=True, timeout=30)
    assert result.returncode == 0, result.stderr
    observed = json.loads(result.stdout)
    assert observed['refusals'] == 2
    assert observed['peak_bytes'] <= 128 * 1024 * 1024
    (tmp_path / 'decoder-resource.json').write_text(json.dumps(observed))


@pytest.mark.parametrize('payload', ['NaN', '{"feature_home_elo":1,"feature_home_elo":2}', '[1]', '[]'])
def test_predict_json_refuses_invalid_shape_and_nonfinite_values_before_frame_creation(tmp_path, monkeypatch, payload):
    from picksports_nfl_ml import cli
    path = tmp_path / 'input.json'
    path.write_text(payload)
    def forbidden(*args, **kwargs):
        raise AssertionError('Invalid prediction JSON reached DataFrame construction')
    monkeypatch.setattr(cli.pd, 'DataFrame', forbidden)
    with pytest.raises(ValueError):
        cli._read_prediction_input(path)


def test_predict_json_row_ceiling_aborts_without_truncation(tmp_path, monkeypatch):
    from picksports_nfl_ml import cli
    path = tmp_path / 'input.json'
    path.write_text(json.dumps([{'feature_home_elo': 1500}] * 1001))
    def forbidden(*args, **kwargs):
        raise AssertionError('Excessive prediction rows reached DataFrame construction')
    monkeypatch.setattr(cli.pd, 'DataFrame', forbidden)
    with pytest.raises(ValueError, match='1000'):
        cli._read_prediction_input(path)


def test_predict_json_keeps_exact_row_ceiling_real_zero_and_nullable_observations(tmp_path):
    from picksports_nfl_ml import cli
    path = tmp_path / 'input.json'
    path.write_text(json.dumps([{'feature_market_total': 0}, {'feature_market_total': None}] * 500))
    frame = cli._read_prediction_input(path)
    assert len(frame) == 1000
    assert frame.iloc[0]['feature_market_total'] == 0
    assert pd.isna(frame.iloc[1]['feature_market_total'])


def test_real_zero_residual_regressor_base_and_outputs_remain_supported():
    values = np.zeros((4, 1), dtype=float)
    original = XGBRegressor(n_estimators=1, max_depth=1, base_score=0, n_jobs=1).fit(values, np.zeros(4))
    restored = load_tree(tree_state(original, kind='xgboost_total_residual', feature_count=1), expected_kind='xgboost_total_residual', feature_count=1)
    np.testing.assert_array_equal(restored.predict(values), np.zeros(4))

@pytest.mark.parametrize('section,field,value', [
    ('total_model', 'baseline_feature', 'foreign_baseline'),
    ('total_model', 'max_residual_weight', .9),
    ('total_model', 'max_abs_adjustment', 100.0),
    ('classifier_blend', 'max_challenger_weight', .9),
])
def test_manifest_fixed_policy_is_bound_to_consumed_schema_before_native(numeric_run, monkeypatch, section, field, value):
    run, _, _ = numeric_run
    path = run / 'manifest.json'
    manifest = json.loads(path.read_text())
    manifest[section][field] = value
    path.write_text(json.dumps(manifest))
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='schema policy'):
        InferenceBundle.load(run)


def test_inventory_growth_reads_only_expected_plus_one(numeric_run, monkeypatch):
    import io
    import os
    run, _, _ = numeric_run
    manifest = json.loads((run / 'manifest.json').read_text())
    target = run / 'explanations/shap_values.parquet'
    expected = manifest['artifacts']['explanations/shap_values.parquet']['bytes']
    inode = target.stat().st_ino
    original_open, original_fdopen = io.open, os.fdopen
    consumed = []
    grown = False
    class Counting:
        def __init__(self, stream): self.stream = stream
        def __getattr__(self, key): return getattr(self.stream, key)
        def __enter__(self): return self
        def __exit__(self, *args): self.stream.close()
        def read(self, size=-1):
            nonlocal grown
            if not grown:
                with original_open(target, 'ab') as output: output.write(b'x' * (1024 * 1024))
                grown = True
            result = self.stream.read(size)
            consumed.append(len(result))
            return result
    def wrapped_open(path, *args, **kwargs):
        stream = original_open(path, *args, **kwargs)
        return Counting(stream) if not isinstance(path, int) and Path(path) == target and args and args[0] == 'rb' else stream
    def wrapped_fdopen(fd, *args, **kwargs):
        stream = original_fdopen(fd, *args, **kwargs)
        return Counting(stream) if os.fstat(fd).st_ino == inode else stream
    monkeypatch.setattr(io, 'open', wrapped_open)
    monkeypatch.setattr(os, 'fdopen', wrapped_fdopen)
    with pytest.raises(ValueError): verify_artifact_inventory(run, manifest)
    assert grown and sum(consumed) <= expected + 1

@pytest.mark.parametrize('role', ['total', 'classifier'])
def test_consumed_baselines_must_be_declared_before_any_native_constructor(numeric_run, monkeypatch, role):
    run, _, _ = numeric_run
    schema_payload = json.loads((run / 'feature_schema.json').read_text())
    manifest = json.loads((run / 'manifest.json').read_text())
    if role == 'total':
        schema_payload['training']['total_model']['baseline_feature'] = 'undeclared_extra_total'
        manifest['total_model']['baseline_feature'] = 'undeclared_extra_total'
    else:
        schema_payload['features'] = {('unused_replacement' if key == 'feature_model_win_probability' else key): value for key, value in schema_payload['features'].items()}
        preprocessor = json.loads((run / 'preprocessor.json').read_text())
        preprocessor['feature_names'] = list(schema_payload['features'])
        reseal(run, 'preprocessor.json', preprocessor)
        manifest['artifacts']['preprocessor.json'] = json.loads((run / 'manifest.json').read_text())['artifacts']['preprocessor.json']
    reseal(run, 'feature_schema.json', schema_payload)
    manifest['artifacts']['feature_schema.json'] = json.loads((run / 'manifest.json').read_text())['artifacts']['feature_schema.json']
    manifest['feature_schema_hash'] = sha256_json(schema_payload)
    manifest['ordered_features'] = list(schema_payload['features'])
    manifest['ordered_feature_hash'] = sha256_json(manifest['ordered_features'])
    (run / 'manifest.json').write_text(json.dumps(manifest))
    native_trap(monkeypatch)
    with pytest.raises(ValueError, match='declared baseline'):
        InferenceBundle.load(run)
