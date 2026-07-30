from __future__ import annotations

import json
import re
import shutil
from pathlib import Path
from typing import Any

import joblib

from picksports_mlb_ml.calibration import ProbabilityCalibrator
from picksports_mlb_ml.hashing import sha256_file
from picksports_mlb_ml.models import ModelSet
from picksports_mlb_ml.schema import FeatureSchema


REQUIRED_ARTIFACTS = {
    "calibrators/logistic_regression_isotonic.joblib",
    "calibrators/logistic_regression_platt.joblib",
    "calibrators/xgboost_isotonic.joblib",
    "calibrators/xgboost_platt.joblib",
    "evaluation.json",
    "feature_schema.yaml",
    "models/logistic_classifier.joblib",
    "models/xgboost_classifier.ubj",
    "models/xgboost_home_margin.ubj",
    "models/xgboost_total_points.ubj",
    "prediction_example.json",
    "preprocessor.joblib",
}

SAFE_RUN_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


def save_run_artifacts(
    output_root: str | Path,
    manifest: dict[str, Any],
    evaluation: dict[str, Any],
    models: ModelSet,
    calibrators: dict[str, dict[str, ProbabilityCalibrator]],
    schema: FeatureSchema,
) -> Path:
    root = Path(output_root).expanduser().resolve()
    run_id = str(manifest["model_run_id"])
    if SAFE_RUN_ID.fullmatch(run_id) is None:
        raise ValueError("Model run ID must be a safe UUID or slug.")
    run_dir = (root / run_id).resolve()
    if run_dir.parent != root:
        raise ValueError("Model run directory must remain inside the output root.")
    if run_dir.exists():
        raise FileExistsError(f"Artifact run directory already exists: {run_dir}")
    (run_dir / "models").mkdir(parents=True)
    (run_dir / "calibrators").mkdir()

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
    _write_json(run_dir / "evaluation.json", evaluation)
    _write_json(run_dir / "prediction_example.json", {})

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


def refresh_artifact_descriptor(run_dir: Path, relative_path: str) -> None:
    manifest_path = run_dir / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    path = run_dir / relative_path
    manifest["artifacts"][relative_path] = {
        "sha256": sha256_file(path),
        "bytes": path.stat().st_size,
    }
    _write_json(manifest_path, manifest)


def verify_artifact_inventory(run_dir: Path, manifest: dict[str, Any]) -> None:
    inventory = manifest.get("artifacts")
    if not isinstance(inventory, dict):
        raise ValueError("Artifact inventory is missing or invalid.")
    missing = sorted(REQUIRED_ARTIFACTS.difference(inventory))
    if missing:
        raise ValueError("Artifact inventory is missing: " + ", ".join(missing))
    actual = {
        str(path.relative_to(run_dir))
        for path in run_dir.rglob("*")
        if path.is_file() and path.name != "manifest.json"
    }
    declared = set(inventory)
    if actual != declared:
        raise ValueError("Run directory does not match its artifact inventory.")
    for relative, descriptor in inventory.items():
        path = run_dir / relative
        if not path.is_file():
            raise FileNotFoundError(f"Artifact is missing: {relative}")
        if path.stat().st_size != int(descriptor["bytes"]):
            raise ValueError(f"Artifact size mismatch: {relative}")
        if sha256_file(path) != descriptor["sha256"]:
            raise ValueError(f"Artifact SHA-256 mismatch: {relative}")


def _write_json(path: Path, value: Any) -> None:
    with path.open("w", encoding="utf-8") as handle:
        json.dump(value, handle, indent=2, sort_keys=True)
        handle.write("\n")
