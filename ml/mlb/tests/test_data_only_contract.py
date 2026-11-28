from pathlib import Path
import json
import joblib
import pytest

from picksports_mlb_ml import __version__
from picksports_mlb_ml.pipeline import train
from picksports_mlb_ml.inference import InferenceBundle


def test_new_artifacts_declare_data_only_execution_format(synthetic_csv, schema_path, tmp_path):
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='numeric-format-positive')
    manifest = json.loads((Path(result['run_dir']) / 'manifest.json').read_text())
    assert manifest.get('execution_format') == 'picksports-tabular-numeric-v1'
    assert manifest.get('package_version') == __version__


def test_missing_execution_format_refuses_before_executable_deserializer(synthetic_csv, schema_path, tmp_path, monkeypatch):
    result = train(synthetic_csv, schema_path, tmp_path / 'artifacts', run_id='legacy-format-refusal')
    root = Path(result['run_dir'])
    path = root / 'manifest.json'
    manifest = json.loads(path.read_text())
    manifest.pop('execution_format', None)
    path.write_text(json.dumps(manifest))

    def forbidden_load(*args, **kwargs):
        raise AssertionError('Executable model deserializer was reached')

    monkeypatch.setattr(joblib, 'load', forbidden_load)
    with pytest.raises(ValueError, match='execution format'):
        InferenceBundle.load(root)


def test_missing_period_execution_format_refuses_before_deserializer(tmp_path, monkeypatch):
    from picksports_mlb_ml.periods import predict_period_models
    bundle = tmp_path / 'legacy-period.json'
    bundle.write_text('{}')
    inputs = tmp_path / 'input.json'
    inputs.write_text('[]')

    def forbidden_load(*args, **kwargs):
        raise AssertionError('Executable period deserializer was reached')

    monkeypatch.setattr(joblib, 'load', forbidden_load)
    with pytest.raises(ValueError, match='execution format'):
        predict_period_models(bundle, inputs)
