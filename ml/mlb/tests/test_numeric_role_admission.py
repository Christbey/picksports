import copy
import hashlib
import json
from pathlib import Path

import numpy as np
import pytest
from xgboost import XGBClassifier

from picksports_mlb_ml.numeric_artifacts import FORMAT, PERIOD_FORMAT, load_period_bundle, tree_state
from picksports_mlb_ml.inference import InferenceBundle
from picksports_mlb_ml.pipeline import train


def period_state():
    values = np.arange(36).reshape(18, 2)
    fitted = XGBClassifier(n_estimators=1, max_depth=1, n_jobs=1).fit(values, np.arange(18) % 3)
    logistic = {'format': FORMAT, 'kind': 'logistic_classifier', 'classes': [0, 1, 2], 'mode': 'multinomial_softmax', 'coefficients': [[0., 0.]] * 3, 'intercepts': [0.] * 3}
    binary = {'format': FORMAT, 'kind': 'logistic_classifier', 'classes': [0, 1], 'mode': 'binary_sigmoid', 'coefficients': [[1.]], 'intercepts': [0.]}
    calibration = {
        'platt': {'format': FORMAT, 'kind': 'probability_calibrator', 'method': 'platt', 'logistic': binary},
        'isotonic': {'format': FORMAT, 'kind': 'probability_calibrator', 'method': 'isotonic', 'x': [0., 1.], 'y': [0., 1.]},
    }
    multiclass = {method: {'format': FORMAT, 'kind': 'multiclass_calibrator', 'method': method, 'probability_floor': .002, 'calibrators': {str(label): copy.deepcopy(state) for label in (0, 1, 2)}} for method, state in calibration.items()}
    market = {
        'preprocessor': {'format': FORMAT, 'kind': 'median_standard_scaler', 'feature_names': ['x', 'y'], 'medians': [0., 0.], 'means': [0., 0.], 'scales': [1., 1.], 'sample_count': 18},
        'models': {'logistic_regression': logistic, 'xgboost': tree_state(fitted, kind='xgboost_multiclass_classifier', feature_count=2)},
        'calibrators': {name: copy.deepcopy(multiclass) for name in ('logistic_regression', 'xgboost')},
        'selected_model': 'logistic_regression', 'selected_calibration': {'logistic_regression': 'platt', 'xgboost': 'isotonic'},
    }
    return {'format': PERIOD_FORMAT, 'manifest': {'execution_format': PERIOD_FORMAT, 'model_run_id': 'synthetic', 'artifact_id': 'synthetic', 'dataset_hash': 'a' * 64, 'model_version': 'synthetic'}, 'features': ['x', 'y'], 'markets': {name: copy.deepcopy(market) for name in ('first_3_moneyline', 'first_5_moneyline')}}


@pytest.mark.parametrize('mutation', ['binary_classes', 'calibrator_role'])
def test_period_role_mismatch_refuses_before_any_native_loader(mutation, monkeypatch):
    state = period_state()
    source = state['markets']['first_5_moneyline']
    if mutation == 'binary_classes':
        source['models']['logistic_regression'].update(classes=[0, 1], mode='binary_sigmoid', coefficients=[[0., 0.]], intercepts=[0.])
    else:
        source['calibrators']['xgboost']['platt'] = copy.deepcopy(source['calibrators']['xgboost']['isotonic'])
    monkeypatch.setattr(XGBClassifier, 'load_model', lambda *args, **kwargs: pytest.fail('Native loader reached before role admission'))
    with pytest.raises(ValueError, match='role'):
        load_period_bundle(state)


def test_tabular_calibrator_role_refuses_before_any_native_loader(synthetic_csv, schema_path, tmp_path, monkeypatch):
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='role-admission')
    root = Path(result['run_dir'])
    target = root / 'calibrators/xgboost_platt.json'
    target.write_bytes((root / 'calibrators/xgboost_isotonic.json').read_bytes())
    path = root / 'manifest.json'
    manifest = json.loads(path.read_text())
    manifest['artifacts']['calibrators/xgboost_platt.json'] = {'bytes': target.stat().st_size, 'sha256': hashlib.sha256(target.read_bytes()).hexdigest()}
    path.write_text(json.dumps(manifest))
    monkeypatch.setattr(XGBClassifier, 'load_model', lambda *args, **kwargs: pytest.fail('Native loader reached before role admission'))
    with pytest.raises(ValueError, match='role'):
        InferenceBundle.load(root)


@pytest.mark.parametrize("same_size", [False, True])
def test_component_replacement_after_inventory_refuses_before_native_load(same_size, synthetic_csv, schema_path, tmp_path, monkeypatch):
    import picksports_mlb_ml.inference as inference
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='post-inventory-replacement')
    root = Path(result['run_dir'])
    original_verify = inference.verify_artifact_inventory

    def interleave(directory, manifest):
        original_verify(directory, manifest)
        target = Path(directory) / 'models/logistic_classifier.json'
        if same_size:
            import re
            original = target.read_text()
            changed, count = re.subn(r'("coefficients":\[\[-?)([0-9])', lambda match: match[1] + str((int(match[2]) + 1) % 10), original, count=1)
            assert count == 1 and len(changed.encode()) == len(original.encode()) and changed != original
            target.write_text(changed)
        else:
            state = json.loads(target.read_text())
            state['coefficients'][0][0] += 1.0
            target.write_text(json.dumps(state))

    monkeypatch.setattr(inference, 'verify_artifact_inventory', interleave)
    monkeypatch.setattr(XGBClassifier, 'load_model', lambda *args, **kwargs: pytest.fail('Native loader reached with replaced numeric state'))
    with pytest.raises(ValueError, match='inventory'):
        InferenceBundle.load(root)


@pytest.mark.parametrize('mutation', ['champion', 'calibrator', 'floor', 'weights', 'anchor', 'residual'])
def test_manifest_execution_selection_refuses_before_any_native_loader(mutation, synthetic_csv, schema_path, tmp_path, monkeypatch):
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='manifest-admission')
    root = Path(result['run_dir'])
    path = root / 'manifest.json'
    manifest = json.loads(path.read_text())
    if mutation == 'champion':
        manifest['champion_classifier'] = 'unknown'
    elif mutation == 'calibrator':
        manifest['selected_calibrators']['xgboost'] = 'unknown'
    elif mutation == 'floor':
        manifest['classifier_blend']['probability_floor'] = 0
    elif mutation == 'weights':
        manifest['classifier_blend']['weights'] = {'logistic_regression': 0., 'xgboost': 0., 'anchor': 1.}
    elif mutation == 'anchor':
        manifest['classifier_blend']['anchor_columns'] = ['unknown_feature']
    else:
        manifest['residual_standard_deviation']['total_points'] = 0.
    path.write_text(json.dumps(manifest))
    monkeypatch.setattr(XGBClassifier, 'load_model', lambda *args, **kwargs: pytest.fail('Native loader reached before manifest admission'))
    with pytest.raises(ValueError, match='manifest'):
        InferenceBundle.load(root)


@pytest.mark.parametrize('value', ['0', '1', '-1'])
def test_binary_native_base_score_refuses_before_constructor(value, monkeypatch):
    from picksports_mlb_ml.numeric_artifacts import load_tree
    values = np.arange(36).reshape(18, 2)
    fitted = XGBClassifier(n_estimators=1, max_depth=1, n_jobs=1).fit(values, np.arange(18) % 2)
    state = tree_state(fitted, kind='xgboost_binary_classifier', feature_count=2)
    state['model']['learner']['learner_model_param']['base_score'] = value
    monkeypatch.setattr(XGBClassifier, '__init__', lambda *args, **kwargs: pytest.fail('Native constructor reached with invalid binary base score'))
    with pytest.raises(ValueError, match='base score'):
        load_tree(state, expected_kind='xgboost_binary_classifier', feature_count=2)


def test_manifest_schema_version_refuses_before_constructor(synthetic_csv, schema_path, tmp_path, monkeypatch):
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='schema-version-admission')
    root = Path(result['run_dir'])
    path = root / 'manifest.json'
    manifest = json.loads(path.read_text())
    manifest['feature_schema_version'] = 'different-schema-version'
    path.write_text(json.dumps(manifest))
    monkeypatch.setattr(XGBClassifier, '__init__', lambda *args, **kwargs: pytest.fail('Native constructor reached with a mismatched schema version'))
    with pytest.raises(ValueError, match='schema version'):
        InferenceBundle.load(root)


@pytest.mark.parametrize('mutation', ['anchor_cap', 'anchor_disabled', 'anchor_disabled_tiny'])
def test_manifest_blend_preserves_consumed_schema_anchor_policy(mutation, synthetic_csv, schema_path, tmp_path, monkeypatch):
    from picksports_mlb_ml.hashing import sha256_json
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='anchor-policy-admission')
    root = Path(result['run_dir'])
    path = root / 'manifest.json'
    manifest = json.loads(path.read_text())
    manifest['classifier_blend']['weights'] = {'logistic_regression': .1, 'xgboost': .1, 'anchor': .8 if mutation == 'anchor_cap' else .2}
    if mutation in ('anchor_disabled', 'anchor_disabled_tiny'):
        anchor = 1e-15 if mutation == 'anchor_disabled_tiny' else .2
        manifest['classifier_blend']['weights'] = {'logistic_regression': .4, 'xgboost': .6 - anchor, 'anchor': anchor}
        schema_file = root / 'feature_schema.json'
        schema = json.loads(schema_file.read_text())
        schema['training']['blend']['enabled'] = False
        schema_file.write_text(json.dumps(schema))
        manifest['feature_schema_hash'] = sha256_json(schema)
        manifest['artifacts']['feature_schema.json'] = {'bytes': schema_file.stat().st_size, 'sha256': hashlib.sha256(schema_file.read_bytes()).hexdigest()}
    path.write_text(json.dumps(manifest))
    monkeypatch.setattr(XGBClassifier, '__init__', lambda *args, **kwargs: pytest.fail('Native constructor reached with mismatched anchor policy'))
    with pytest.raises(ValueError, match='anchor policy'):
        InferenceBundle.load(root)


def test_probability_blend_grid_never_rounds_above_declared_anchor_cap():
    from picksports_mlb_ml.blending import select_probability_blend
    labels = np.tile([0, 1], 20)
    anchors = np.where(labels == 1, .99, .01)
    models = {'logistic_regression': np.full(40, .5), 'xgboost': np.full(40, .5)}
    blend = select_probability_blend(models, anchors, labels, maximum_anchor_weight=.66)
    assert blend['weights']['anchor'] <= .66
    assert blend['weights']['anchor'] == .6
