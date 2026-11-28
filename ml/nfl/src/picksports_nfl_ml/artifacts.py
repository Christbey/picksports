from __future__ import annotations

import json
import re
import os
import hashlib
import stat
from pathlib import Path
from typing import Any

import pandas as pd

from picksports_nfl_ml.calibration import ProbabilityCalibrator
from picksports_nfl_ml.hashing import sha256_file, sha256_json
from picksports_nfl_ml.models import ModelSet
from picksports_nfl_ml.numeric_artifacts import (
    TABULAR_FORMAT, MAX_COMPONENT_BYTES, MAX_METADATA_BYTES,
    preprocessor_state, logistic_state, calibrator_state, tree_state, write_json,
)
from picksports_nfl_ml.schema import FeatureSchema


REQUIRED_ARTIFACTS = {
    "calibrators/logistic_regression_isotonic.json",
    "calibrators/logistic_regression_platt.json",
    "calibrators/xgboost_isotonic.json",
    "calibrators/xgboost_platt.json",
    "evaluation.json",
    "explanations/global_shap_importance.json",
    "explanations/shap_values.parquet",
    "explanations/xgboost_feature_importance.json",
    "feature_schema.json",
    "models/logistic_classifier.json",
    "models/xgboost_classifier.json",
    "models/xgboost_home_margin.json",
    "models/xgboost_total_points.json",
    "prediction_example.json",
    "preprocessor.json",
    "tuning/optuna_provenance.json",
}

SAFE_RUN_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$")


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
    (run_dir / "tuning").mkdir()
    (run_dir / "explanations").mkdir()

    manifest["execution_format"] = TABULAR_FORMAT
    manifest["manifest_version"] = 2
    manifest["ordered_features"] = schema.feature_names
    manifest["ordered_feature_hash"] = sha256_json(schema.feature_names)
    write_json(run_dir / "preprocessor.json", preprocessor_state(models.preprocessor, schema.feature_names))
    write_json(run_dir / "models/logistic_classifier.json", logistic_state(models.logistic_classifier))
    for filename, model, kind in [
        ("xgboost_classifier.json", models.xgboost_classifier, "xgboost_home_win_classifier"),
        ("xgboost_home_margin.json", models.margin_regressor, "xgboost_home_margin"),
        ("xgboost_total_points.json", models.total_regressor, "xgboost_total_residual"),
    ]:
        write_json(run_dir / "models" / filename, tree_state(model, kind=kind, feature_count=len(schema.feature_names)))
    if set(calibrators) != {"logistic_regression", "xgboost"}:
        raise ValueError("NFL numeric calibration roles are incomplete")
    for model_name, methods in calibrators.items():
        if set(methods) != {"platt", "isotonic"}:
            raise ValueError("NFL numeric calibration methods are incomplete")
        for method, calibrator in methods.items():
            if calibrator.method != method:
                raise ValueError("NFL calibration method differs from its file role")
            write_json(run_dir / "calibrators" / f"{model_name}_{method}.json", calibrator_state(calibrator))

    # Schema mapping order is the fitted numeric feature order, not cosmetic metadata.
    write_json(run_dir / "feature_schema.json", schema.raw, max_bytes=MAX_METADATA_BYTES, max_tokens=50000, sort_keys=False)
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


MAX_RUN_BYTES = 128 * 1024 * 1024
MAX_EXPLANATION_BYTES = 64 * 1024 * 1024


def artifact_limit(relative: str) -> int:
    if relative == "explanations/shap_values.parquet":
        return MAX_EXPLANATION_BYTES
    if relative == "preprocessor.json" or relative.startswith(("models/", "calibrators/")):
        return MAX_COMPONENT_BYTES
    return MAX_METADATA_BYTES


def verify_artifact_inventory(run_dir: Path, manifest: dict[str, Any]) -> None:
    if run_dir.is_symlink() or (run_dir / "manifest.json").is_symlink():
        raise ValueError("Artifact directories and manifests must not be symlinks.")
    if manifest.get("execution_format") != TABULAR_FORMAT:
        raise ValueError("Only the NFL numeric execution format is supported.")
    inventory = manifest.get("artifacts")
    if type(inventory) is not dict:
        raise ValueError("NFL numeric artifact inventory must match the exact supported file set.")
    for relative in inventory:
        if type(relative) is not str or not relative or "\\" in relative or Path(relative).is_absolute() or any(part in {"", ".", ".."} for part in relative.split("/")):
            raise ValueError("Artifact inventory paths must be canonical relative paths.")
    if set(inventory) != REQUIRED_ARTIFACTS:
        raise ValueError("NFL numeric artifact inventory must match the exact supported file set.")
    total = 0
    for relative, descriptor in inventory.items():
        if type(descriptor) is not dict or set(descriptor) != {"bytes", "sha256"} or type(descriptor.get("bytes")) is not int or not 0 <= descriptor["bytes"] <= artifact_limit(relative) or type(descriptor.get("sha256")) is not str or re.fullmatch(r"[a-f0-9]{64}", descriptor["sha256"]) is None:
            raise ValueError("Artifact inventory descriptors exceed their fixed role budget.")
        total += descriptor["bytes"]
    if total > MAX_RUN_BYTES:
        raise ValueError("NFL numeric run exceeds its aggregate byte budget.")
    allowed_directories = {"models", "calibrators", "tuning", "explanations"}
    actual = set()
    pending = [run_dir]
    entries = 0
    while pending:
        directory = pending.pop()
        with os.scandir(directory) as children:
            for item in children:
                entries += 1
                if entries > len(REQUIRED_ARTIFACTS) + len(allowed_directories) + 1:
                    raise ValueError("NFL numeric run exceeds its exact entry budget.")
                relative = str(Path(item.path).relative_to(run_dir))
                if item.is_symlink():
                    raise ValueError("Artifact inventories must not contain symlinks.")
                if item.is_dir(follow_symlinks=False):
                    if relative not in allowed_directories:
                        raise ValueError("Unsupported NFL numeric artifact directory.")
                    pending.append(Path(item.path))
                elif item.is_file(follow_symlinks=False):
                    if relative not in REQUIRED_ARTIFACTS | {"manifest.json"}:
                        raise ValueError("Unsupported NFL numeric artifact file.")
                    if relative != "manifest.json":
                        actual.add(relative)
                else:
                    raise ValueError("NFL numeric artifacts must be regular files.")
    if actual != REQUIRED_ARTIFACTS:
        raise ValueError("Run directory does not match its artifact inventory.")
    for relative, descriptor in inventory.items():
        path = run_dir / relative
        if _bounded_artifact_hash(path, descriptor["bytes"]) != descriptor["sha256"]:
            raise ValueError(f"Artifact size or SHA-256 mismatch: {relative}")


def _write_json(path: Path, value: Any) -> None:
    write_json(path, value, max_bytes=MAX_METADATA_BYTES, max_tokens=50000)


def _artifact_descriptor(path: Path, run_dir: Path) -> dict[str, Any]:
    return {
        "path": str(path.relative_to(run_dir)),
        "sha256": sha256_file(path),
        "bytes": path.stat().st_size,
    }


def _bounded_artifact_hash(path: Path, expected_bytes: int) -> str:
    """Inventory admission consumes at most the admitted size plus one byte."""
    before = path.stat(follow_symlinks=False)
    if not stat.S_ISREG(before.st_mode) or before.st_size != expected_bytes:
        raise ValueError("Artifact type or admitted size mismatch before hashing.")
    descriptor = os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0))
    with os.fdopen(descriptor, "rb") as handle:
        opened = os.fstat(handle.fileno())
        identity = (before.st_dev, before.st_ino)
        if not stat.S_ISREG(opened.st_mode) or (opened.st_dev, opened.st_ino) != identity or opened.st_size != expected_bytes:
            raise ValueError("Artifact identity or size changed before hashing.")
        digest = hashlib.sha256()
        consumed = 0
        while True:
            chunk = handle.read(min(65536, expected_bytes + 1 - consumed))
            consumed += len(chunk)
            if consumed > expected_bytes:
                raise ValueError("Artifact grew beyond its admitted byte length.")
            if not chunk:
                break
            digest.update(chunk)
        after = os.fstat(handle.fileno())
        current = path.stat(follow_symlinks=False)
        if consumed != expected_bytes or after.st_size != expected_bytes or (current.st_dev, current.st_ino) != identity or current.st_size != expected_bytes or after.st_mtime_ns != opened.st_mtime_ns or after.st_ctime_ns != opened.st_ctime_ns:
            raise ValueError("Artifact changed while its bounded inventory was hashed.")
        return digest.hexdigest()
