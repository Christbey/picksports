from __future__ import annotations

import json
import shutil
from pathlib import Path
from typing import Any

import joblib
import pandas as pd

from picksports_nfl_ml.calibration import ProbabilityCalibrator
from picksports_nfl_ml.hashing import sha256_file
from picksports_nfl_ml.models import ModelSet
from picksports_nfl_ml.schema import FeatureSchema


def save_run_artifacts(
    output_root: str | Path,
    manifest: dict[str, Any],
    evaluation: dict[str, Any],
    example_output: dict[str, Any],
    models: ModelSet,
    calibrators: dict[str, dict[str, ProbabilityCalibrator]],
    schema: FeatureSchema,
    tuning_provenance: dict[str, Any],
    explanation_summary: dict[str, Any],
    explanation_values: pd.DataFrame,
    native_feature_importance: dict[str, Any],
) -> Path:
    run_dir = Path(output_root).expanduser().resolve() / manifest["model_run_id"]
    if run_dir.exists():
        raise FileExistsError(f"Artifact run directory already exists: {run_dir}")
    (run_dir / "models").mkdir(parents=True)
    (run_dir / "calibrators").mkdir()
    (run_dir / "tuning").mkdir()
    (run_dir / "explanations").mkdir()

    joblib.dump(models.preprocessor, run_dir / "preprocessor.joblib")
    joblib.dump(
        models.logistic_classifier,
        run_dir / "models" / "logistic_classifier.joblib",
    )
    models.xgboost_classifier.save_model(
        run_dir / "models" / "xgboost_classifier.ubj"
    )
    models.margin_regressor.save_model(
        run_dir / "models" / "xgboost_home_margin.ubj"
    )
    models.total_regressor.save_model(
        run_dir / "models" / "xgboost_total_points.ubj"
    )
    for model_name, methods in calibrators.items():
        for method, calibrator in methods.items():
            joblib.dump(
                calibrator,
                run_dir / "calibrators" / f"{model_name}_{method}.joblib",
            )

    shutil.copy2(schema.path, run_dir / "feature_schema.yaml")
    tuning_path = run_dir / "tuning" / "optuna_provenance.json"
    shap_summary_path = run_dir / "explanations" / "global_shap_importance.json"
    shap_values_path = run_dir / "explanations" / "shap_values.parquet"
    native_importance_path = (
        run_dir / "explanations" / "xgboost_feature_importance.json"
    )
    _write_json(tuning_path, tuning_provenance)
    _write_json(shap_summary_path, explanation_summary)
    explanation_values.to_parquet(shap_values_path, index=False)
    _write_json(native_importance_path, native_feature_importance)

    tuning_artifact = _artifact_descriptor(tuning_path, run_dir)
    explanation_artifacts = {
        "global_shap_importance": _artifact_descriptor(shap_summary_path, run_dir),
        "shap_values": _artifact_descriptor(shap_values_path, run_dir),
        "xgboost_feature_importance": _artifact_descriptor(
            native_importance_path, run_dir
        ),
    }
    manifest["tuning"] = {
        "enabled": bool(tuning_provenance.get("enabled")),
        "status": tuning_provenance.get("status"),
        "artifact": tuning_artifact,
    }
    manifest["explanations"] = {
        "enabled": bool(explanation_summary.get("enabled")),
        "status": explanation_summary.get("status"),
        "sample_rows": explanation_summary.get("sample_rows", 0),
        "artifacts": explanation_artifacts,
    }
    evaluation.setdefault("tuning", {})["artifact"] = tuning_artifact
    evaluation.setdefault("explanations", {}).update(
        {
            "enabled": bool(explanation_summary.get("enabled")),
            "status": explanation_summary.get("status"),
            "sample_rows": explanation_summary.get("sample_rows", 0),
            "artifacts": explanation_artifacts,
        }
    )
    _write_json(run_dir / "evaluation.json", evaluation)
    _write_json(run_dir / "prediction_example.json", example_output)

    artifact_files = sorted(
        path
        for path in run_dir.rglob("*")
        if path.is_file() and path.name != "manifest.json"
    )
    manifest["artifacts"] = {
        str(path.relative_to(run_dir)): {
            "sha256": sha256_file(path),
            "bytes": path.stat().st_size,
        }
        for path in artifact_files
    }
    _write_json(run_dir / "manifest.json", manifest)
    return run_dir


def _write_json(path: Path, value: Any) -> None:
    with path.open("w", encoding="utf-8") as handle:
        json.dump(value, handle, indent=2, sort_keys=True)
        handle.write("\n")


def _artifact_descriptor(path: Path, run_dir: Path) -> dict[str, Any]:
    return {
        "path": str(path.relative_to(run_dir)),
        "sha256": sha256_file(path),
        "bytes": path.stat().st_size,
    }
