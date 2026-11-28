from __future__ import annotations

import json
import math
import re
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
from xgboost import XGBClassifier, XGBRegressor

from picksports_nfl_ml.artifacts import verify_artifact_inventory
from picksports_nfl_ml.blending import weighted_probabilities
from picksports_nfl_ml.numeric_artifacts import (
    TABULAR_FORMAT, MAX_METADATA_BYTES, read_json, load_preprocessor, load_logistic,
    load_calibrator, load_tree, validate_tree_state, NumericCalibrator,
)
from picksports_nfl_ml.hashing import sha256_json
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.totals import blended_total_predictions, total_model_config


@dataclass
class InferenceBundle:
    run_dir: Path
    manifest: dict[str, Any]
    schema: FeatureSchema
    preprocessor: Any
    logistic_classifier: Any
    xgboost_classifier: XGBClassifier
    margin_regressor: XGBRegressor
    total_regressor: XGBRegressor
    calibrators: dict[str, NumericCalibrator]

    @classmethod
    def load(cls, run_dir: str | Path) -> "InferenceBundle":
        candidate = Path(run_dir).expanduser()
        if candidate.is_symlink() or (candidate / "manifest.json").is_symlink():
            raise ValueError("Artifact directories and manifests must not be symlinks.")
        root = candidate.resolve()
        manifest = read_json(root / "manifest.json", max_bytes=MAX_METADATA_BYTES, max_tokens=50000)
        if type(manifest) is not dict or manifest.get("execution_format") != TABULAR_FORMAT:
            raise ValueError("Only the NFL numeric execution format is supported.")
        _validate_manifest_policy(manifest)
        verify_artifact_inventory(root, manifest)
        inventory = manifest["artifacts"]

        def component(name: str, metadata: bool = False):
            limit = MAX_METADATA_BYTES if metadata else 8 * 1024 * 1024
            return read_json(root / name, max_bytes=limit, max_tokens=50000 if metadata else 1_000_000,
                             expected_sha256=inventory[name]["sha256"], expected_bytes=inventory[name]["bytes"])

        schema = FeatureSchema.from_mapping(root / "feature_schema.json", component("feature_schema.json", True))
        if schema.hash != manifest.get("feature_schema_hash"):
            raise ValueError("Feature schema hash does not match the registered run manifest.")
        if schema.version != manifest.get("feature_schema_version"):
            raise ValueError("Feature schema version differs from the NFL run manifest.")
        if manifest.get("ordered_features") != schema.feature_names or manifest.get("ordered_feature_hash") != sha256_json(schema.feature_names):
            raise ValueError("Ordered numeric features differ from the fitted NFL contract.")
        _validate_schema_policy(manifest, schema)
        preprocessor = load_preprocessor(component("preprocessor.json"))
        logistic = load_logistic(component("models/logistic_classifier.json"))
        if preprocessor.feature_names != schema.feature_names or logistic.coefficients.shape != (1, len(schema.feature_names)):
            raise ValueError("NFL numeric estimator dimensions differ from the ordered schema.")
        calibrators = {}
        for model_name in ("logistic_regression", "xgboost"):
            for method in ("platt", "isotonic"):
                calibrator = load_calibrator(component(f"calibrators/{model_name}_{method}.json"))
                if calibrator.method != method:
                    raise ValueError("NFL numeric calibrator differs from its fixed role.")
                calibrators[f"{model_name}:{method}"] = calibrator
        states = {
            "xgboost_home_win_classifier": component("models/xgboost_classifier.json"),
            "xgboost_home_margin": component("models/xgboost_home_margin.json"),
            "xgboost_total_residual": component("models/xgboost_total_points.json"),
        }
        # Validate every component before invoking a native model loader.
        for kind, state in states.items():
            validate_tree_state(state, expected_kind=kind, feature_count=len(schema.feature_names))
        xgb_classifier = load_tree(states["xgboost_home_win_classifier"], expected_kind="xgboost_home_win_classifier", feature_count=len(schema.feature_names))
        margin = load_tree(states["xgboost_home_margin"], expected_kind="xgboost_home_margin", feature_count=len(schema.feature_names))
        total = load_tree(states["xgboost_total_residual"], expected_kind="xgboost_total_residual", feature_count=len(schema.feature_names))
        return cls(
            run_dir=root,
            manifest=manifest,
            schema=schema,
            preprocessor=preprocessor,
            logistic_classifier=logistic,
            xgboost_classifier=xgb_classifier,
            margin_regressor=margin,
            total_regressor=total,
            calibrators=calibrators,
        )

    def predict(self, frame: pd.DataFrame) -> list[dict[str, Any]]:
        validated = self.schema.validate_inference_frame(frame)
        transformed = self.preprocessor.transform(validated[self.schema.feature_names])
        logistic_raw = self.logistic_classifier.predict_proba(transformed)[:, 1]
        xgboost_raw = self.xgboost_classifier.predict_proba(transformed)[:, 1]
        raw = {
            "logistic_regression": np.asarray(logistic_raw, dtype=float),
            "xgboost": np.asarray(xgboost_raw, dtype=float),
        }
        calibrated: dict[str, np.ndarray] = {}
        selected_methods = self.manifest["selected_calibrators"]
        for model_name, probabilities in raw.items():
            calibrator = self.calibrators[
                f"{model_name}:{selected_methods[model_name]}"
            ]
            calibrated[model_name] = calibrator.predict(probabilities)

        champion = self.manifest["champion_classifier"]
        if champion == "blend":
            blend = self.manifest["classifier_blend"]
            baseline = pd.to_numeric(
                validated["feature_model_win_probability"],
                errors="coerce",
            ).fillna(0.5)
            win_probabilities = np.clip(
                weighted_probabilities(
                    calibrated,
                    baseline.to_numpy(dtype=float),
                    blend["weights"],
                ),
                float(blend["probability_floor"]),
                1 - float(blend["probability_floor"]),
            )
        else:
            win_probabilities = calibrated[champion]
        margins = self.margin_regressor.predict(transformed)
        raw_totals = self.total_regressor.predict(transformed)
        totals = blended_total_predictions(validated, raw_totals, self.schema, self.manifest["total_model"])
        outputs: list[dict[str, Any]] = []
        for index, (_, row) in enumerate(validated.iterrows()):
            feature_payload = {
                name: _nullable_float(row[name]) for name in self.schema.feature_names
            }
            home_line = feature_payload.get("feature_market_home_spread")
            market_total = feature_payload.get("feature_market_total")
            probability = float(win_probabilities[index])
            disagreement = abs(
                float(calibrated["logistic_regression"][index])
                - float(calibrated["xgboost"][index])
            )
            entropy = _binary_entropy(probability)
            uncertainty = min(1.0, 0.75 * entropy + 0.25 * min(1.0, disagreement * 4))
            outputs.append(
                {
                    "home_win_probability": round(probability, 6),
                    "expected_home_margin": round(float(margins[index]), 4),
                    "expected_total": round(float(totals[index]), 4),
                    "home_cover_probability": _probability_above(
                        threshold=home_line,
                        mean=float(margins[index]),
                        standard_deviation=float(
                            self.manifest["residual_standard_deviation"]["home_margin"]
                        ),
                    ),
                    "over_probability": _probability_above(
                        threshold=market_total,
                        mean=float(totals[index]),
                        standard_deviation=float(
                            self.manifest["residual_standard_deviation"]["total_points"]
                        ),
                    ),
                    "uncertainty": round(uncertainty, 6),
                    "model_run_id": self.manifest["model_run_id"],
                    "artifact_id": self.manifest["artifact_id"],
                    "dataset_hash": self.manifest["dataset_hash"],
                    "feature_hash": sha256_json(feature_payload),
                }
            )
        return outputs


def _probability_above(
    threshold: float | None,
    mean: float,
    standard_deviation: float,
) -> float | None:
    if threshold is None or not math.isfinite(threshold):
        return None
    if standard_deviation <= 0:
        return 1.0 if mean > threshold else 0.0
    z_score = (threshold - mean) / standard_deviation
    probability = 0.5 * math.erfc(z_score / math.sqrt(2))
    return round(min(1.0, max(0.0, probability)), 6)


def _binary_entropy(probability: float) -> float:
    clipped = min(1 - 1e-12, max(1e-12, probability))
    return -(
        clipped * math.log(clipped) + (1 - clipped) * math.log(1 - clipped)
    ) / math.log(2)


def _nullable_float(value: Any) -> float | None:
    return None if pd.isna(value) else float(value)


def _finite_number(value: Any) -> bool:
    return type(value) in (int, float) and math.isfinite(value)


def _validate_manifest_policy(manifest: dict[str, Any]) -> None:
    if manifest.get("manifest_version") != 2 or type(manifest.get("manifest_version")) is not int or manifest.get("model_type") != "nfl_tabular_bundle":
        raise ValueError("Unsupported NFL numeric manifest contract.")
    for field in ("model_run_id", "artifact_id", "dataset_hash", "feature_schema_hash", "config_hash", "feature_schema_version"):
        if type(manifest.get(field)) is not str or not manifest[field]:
            raise ValueError("NFL numeric lineage is incomplete.")
    for field in ("dataset_hash", "feature_schema_hash", "config_hash", "ordered_feature_hash"):
        if type(manifest.get(field)) is not str or re.fullmatch(r"[a-f0-9]{64}", manifest[field]) is None:
            raise ValueError("NFL numeric lineage hash is invalid.")
    if re.fullmatch(r"[A-Za-z0-9][A-Za-z0-9._-]{0,127}", manifest["model_run_id"]) is None:
        raise ValueError("NFL numeric model run identity is invalid.")
    try:
        if str(uuid.UUID(manifest["artifact_id"])) != manifest["artifact_id"]:
            raise ValueError("NFL numeric artifact UUID is not canonical.")
    except (ValueError, AttributeError) as error:
        raise ValueError("NFL numeric artifact UUID is invalid.") from error
    if manifest.get("model_version") != "nfl-tabular-v3" or manifest.get("blend_version") != "baseline-anchored-v2":
        raise ValueError("Unsupported NFL numeric model/blend policy.")
    methods = manifest.get("selected_calibrators")
    if type(methods) is not dict or set(methods) != {"logistic_regression", "xgboost"} or any(value not in ("platt", "isotonic") for value in methods.values()):
        raise ValueError("Invalid NFL numeric selected calibration policy.")
    if manifest.get("champion_classifier") not in ("logistic_regression", "xgboost", "blend"):
        raise ValueError("Invalid NFL numeric champion.")
    blend = manifest.get("classifier_blend")
    if type(blend) is not dict or type(blend.get("weights")) is not dict or set(blend["weights"]) != {"logistic_regression", "xgboost", "current_picksports"}:
        raise ValueError("NFL numeric probability blend is incomplete.")
    weights = blend["weights"]
    if any(not _finite_number(value) or not 0 <= value <= 1 for value in weights.values()) or abs(sum(weights.values()) - 1) > 1e-12:
        raise ValueError("Invalid NFL numeric probability blend weights.")
    maximum = blend.get("max_challenger_weight")
    if not _finite_number(maximum) or not 0 <= maximum <= 1 or weights["logistic_regression"] + weights["xgboost"] > maximum + 1e-12:
        raise ValueError("NFL numeric challenger weight exceeds its selected bound.")
    floor = blend.get("probability_floor")
    if not _finite_number(floor) or not 0 < floor < 0.5:
        raise ValueError("Invalid NFL numeric probability floor.")
    total = manifest.get("total_model")
    if type(total) is not dict or total.get("strategy") != "baseline_residual_blend" or type(total.get("baseline_feature")) is not str or not total["baseline_feature"]:
        raise ValueError("NFL numeric total model must retain baseline residual semantics.")
    for field in ("baseline_fallback", "selected_residual_weight", "max_residual_weight", "max_abs_adjustment"):
        if not _finite_number(total.get(field)):
            raise ValueError("NFL numeric total policy must be finite.")
    if not 0 <= total["selected_residual_weight"] <= total["max_residual_weight"] <= 1 or total["max_abs_adjustment"] < 0:
        raise ValueError("Invalid NFL numeric total correction bounds.")
    residuals = manifest.get("residual_standard_deviation")
    if type(residuals) is not dict or set(residuals) != {"home_margin", "total_points"} or any(not _finite_number(value) or value <= 0 for value in residuals.values()):
        raise ValueError("NFL numeric residual scales must be positive and finite.")


def _validate_schema_policy(manifest: dict[str, Any], schema: FeatureSchema) -> None:
    expected = total_model_config(schema)
    total = manifest["total_model"]
    if expected["baseline_feature"] not in schema.feature_names or (manifest["champion_classifier"] == "blend" and "feature_model_win_probability" not in schema.feature_names):
        raise ValueError("NFL numeric inference requires every consumed declared baseline feature.")
    for field in ("strategy", "baseline_feature", "max_residual_weight", "max_abs_adjustment"):
        if total.get(field) != expected[field]:
            raise ValueError("NFL manifest total schema policy differs from the consumed schema.")
    blend_config = schema.training.get("blend", {})
    maximum = blend_config.get("max_challenger_weight", 0.5)
    if not _finite_number(maximum) or manifest["classifier_blend"]["max_challenger_weight"] != float(maximum):
        raise ValueError("NFL manifest classifier schema policy differs from the consumed schema.")
