from pathlib import Path
import shutil

import joblib
import pytest

from picksports_nfl_ml.artifacts import verify_artifact_inventory

LEGACY_ARTIFACTS = {'evaluation.json', 'models/xgboost_classifier.ubj', 'calibrators/xgboost_isotonic.joblib', 'models/xgboost_home_margin.ubj', 'calibrators/logistic_regression_platt.joblib', 'explanations/shap_values.parquet', 'explanations/xgboost_feature_importance.json', 'prediction_example.json', 'explanations/global_shap_importance.json', 'feature_schema.yaml', 'tuning/optuna_provenance.json', 'calibrators/xgboost_platt.joblib', 'calibrators/logistic_regression_isotonic.joblib', 'preprocessor.joblib', 'models/xgboost_total_points.ubj', 'models/logistic_classifier.joblib'}
from picksports_nfl_ml.hashing import sha256_file
from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.schema import FeatureSchema


def legacy_inventory(root: Path, schema_path: Path) -> dict:
    for name in LEGACY_ARTIFACTS:
        target = root / name
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_bytes(b'synthetic, never executed')
    shutil.copy2(schema_path, root / 'feature_schema.yaml')
    manifest = {'feature_schema_hash': FeatureSchema.load(schema_path).hash, 'artifacts': {
        name: {'bytes': (root / name).stat().st_size, 'sha256': sha256_file(root / name)}
        for name in LEGACY_ARTIFACTS
    }}
    import json
    (root / 'manifest.json').write_text(json.dumps(manifest))
    return manifest


def test_rejects_a_hash_matched_legacy_bundle_before_any_executable_loader(tmp_path, schema_path, monkeypatch):
    root = tmp_path / 'legacy'
    legacy_inventory(root, schema_path)
    def forbidden(*args, **kwargs):
        raise RuntimeError('Executable loader trap reached; no artifact was deserialized')
    monkeypatch.setattr(joblib, 'load', forbidden)
    with pytest.raises(ValueError, match='numeric'):
        InferenceBundle.load(root)


def test_rejects_declared_executable_extras_in_an_otherwise_matching_inventory(tmp_path, schema_path):
    root = tmp_path / 'legacy'
    manifest = legacy_inventory(root, schema_path)
    extra = root / 'extra.joblib'
    extra.write_bytes(b'synthetic, never executed')
    manifest['artifacts']['extra.joblib'] = {'bytes': extra.stat().st_size, 'sha256': sha256_file(extra)}
    with pytest.raises(ValueError, match='numeric|exact|unsupported'):
        verify_artifact_inventory(root, manifest)
