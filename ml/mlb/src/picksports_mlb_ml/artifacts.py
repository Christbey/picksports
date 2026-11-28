from __future__ import annotations

import json
import hashlib
import os
import re
import shutil
from pathlib import Path
from typing import Any


from picksports_mlb_ml.calibration import ProbabilityCalibrator
from picksports_mlb_ml.hashing import sha256_file
from picksports_mlb_ml.models import ModelSet
from picksports_mlb_ml.schema import FeatureSchema
from picksports_mlb_ml.numeric_artifacts import (TABULAR_FORMAT, calibrator_state, logistic_state, preprocessor_state, tree_state, write_json)


REQUIRED_ARTIFACTS = {
    "calibrators/logistic_regression_isotonic.json",
    "calibrators/logistic_regression_platt.json",
    "calibrators/xgboost_isotonic.json",
    "calibrators/xgboost_platt.json",
    "evaluation.json",
    "feature_schema.json",
    "models/logistic_classifier.json",
    "models/xgboost_classifier.json",
    "models/xgboost_home_margin.json",
    "models/xgboost_total_points.json",
    "prediction_example.json",
    "preprocessor.json",
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

    manifest["execution_format"] = TABULAR_FORMAT
    write_json(run_dir / "preprocessor.json", preprocessor_state(models.preprocessor, schema.feature_names))
    write_json(run_dir / "models" / "logistic_classifier.json", logistic_state(models.logistic_classifier))
    for filename, estimator, kind in [
        ("xgboost_classifier.json", models.xgboost_classifier, "xgboost_binary_classifier"),
        ("xgboost_home_margin.json", models.margin_regressor, "xgboost_regressor"),
        ("xgboost_total_points.json", models.total_regressor, "xgboost_regressor"),
    ]:
        write_json(run_dir / "models" / filename, tree_state(estimator, kind=kind, feature_count=len(schema.feature_names)))
    for model_name, methods in calibrators.items():
        for method, calibrator in methods.items():
            write_json(run_dir / "calibrators" / f"{model_name}_{method}.json", calibrator_state(calibrator))
    write_json(run_dir / "feature_schema.json", schema.raw, max_bytes=2 * 1024 * 1024, sort_keys=False)
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
    if run_dir.is_symlink() or (run_dir / "manifest.json").is_symlink():
        raise ValueError("Artifact directories and manifests must not be symlinks.")
    inventory = manifest.get("artifacts")
    if not isinstance(inventory, dict):
        raise ValueError("Artifact inventory is missing or invalid.")
    for relative, descriptor in inventory.items():
        if (
            not isinstance(relative, str)
            or not relative
            or "\\" in relative
            or Path(relative).is_absolute()
            or any(part in {"", ".", ".."} for part in relative.split("/"))
        ):
            raise ValueError("Artifact inventory paths must be canonical relative paths.")
        if (
            not isinstance(descriptor, dict)
            or type(descriptor.get("bytes")) is not int
            or descriptor["bytes"] < 0
            or not isinstance(descriptor.get("sha256"), str)
            or re.fullmatch(r"[0-9a-f]{64}", descriptor["sha256"]) is None
        ):
            raise ValueError("Artifact inventory descriptors must contain a size and SHA-256.")
    missing = sorted(REQUIRED_ARTIFACTS.difference(inventory))
    if missing:
        raise ValueError("Artifact inventory is missing: " + ", ".join(missing))
    if set(inventory) != REQUIRED_ARTIFACTS:
        raise ValueError("Numeric artifact inventory must contain only the fixed execution files.")
    files: dict[str, Path] = {}
    scanned = total = 0
    pending = [(run_dir, 0)]
    while pending:
        directory, depth = pending.pop()
        if depth > 8:
            raise ValueError("Artifact directory nesting exceeds its budget.")
        with os.scandir(directory) as entries:
            for entry in entries:
                scanned += 1
                if scanned > 512:
                    raise ValueError("Artifact directory exceeds its entry budget.")
                if entry.is_symlink():
                    raise ValueError("Artifact inventories must not contain symlinks.")
                path = Path(entry.path)
                if entry.is_dir(follow_symlinks=False):
                    pending.append((path, depth + 1))
                elif entry.is_file(follow_symlinks=False):
                    relative = str(path.relative_to(run_dir))
                    if relative == "manifest.json":
                        continue
                    files[relative] = path
                    if len(files) > 64:
                        raise ValueError("Artifact inventory exceeds its file budget.")
                    size = entry.stat(follow_symlinks=False).st_size
                    limit = 8 * 1024 * 1024 if relative.startswith(("models/", "calibrators/")) or relative == "preprocessor.json" else 2 * 1024 * 1024
                    total += size
                    if size > limit or total > 80 * 1024 * 1024:
                        raise ValueError("Artifact inventory exceeds its byte budget.")
                else:
                    raise ValueError("Artifact inventory must contain only regular files.")
    if set(files) != set(inventory):
        raise ValueError("Run directory does not match its artifact inventory.")
    for relative, descriptor in inventory.items():
        path = files[relative]
        before = path.stat()
        if before.st_size != descriptor["bytes"]:
            raise ValueError(f"Artifact size mismatch: {relative}")
        digest = hashlib.sha256()
        with os.fdopen(os.open(path, os.O_RDONLY | getattr(os, "O_NOFOLLOW", 0)), "rb") as handle:
            opened = os.fstat(handle.fileno())
            if (opened.st_dev, opened.st_ino, opened.st_size) != (before.st_dev, before.st_ino, before.st_size):
                raise ValueError("Artifact identity changed before hashing.")
            count = 0
            while chunk := handle.read(min(65536, before.st_size - count + 1)):
                count += len(chunk)
                if count > before.st_size:
                    raise ValueError("Artifact grew during hashing.")
                digest.update(chunk)
            after = os.fstat(handle.fileno())
        current = path.stat()
        if count != before.st_size or (after.st_mtime_ns, after.st_ctime_ns) != (before.st_mtime_ns, before.st_ctime_ns) or (current.st_dev, current.st_ino) != (before.st_dev, before.st_ino):
            raise ValueError("Artifact changed during hashing.")
        if digest.hexdigest() != descriptor["sha256"]:
            raise ValueError(f"Artifact SHA-256 mismatch: {relative}")


def _write_json(path: Path, value: Any) -> None:
    with path.open("w", encoding="utf-8") as handle:
        json.dump(value, handle, indent=2, sort_keys=True)
        handle.write("\n")
