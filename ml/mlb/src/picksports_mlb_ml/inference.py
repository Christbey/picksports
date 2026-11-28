from __future__ import annotations

import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
from xgboost import XGBClassifier, XGBRegressor

from picksports_mlb_ml.artifacts import verify_artifact_inventory
from picksports_mlb_ml.blending import anchor_probabilities, weighted_probabilities
from picksports_mlb_ml.hashing import sha256_json
from picksports_mlb_ml.schema import FeatureSchema
from picksports_mlb_ml.numeric_artifacts import (NumericPreprocessor, NumericLogistic, NumericCalibrator, TABULAR_FORMAT, read_json, load_preprocessor, load_logistic, load_calibrator, validate_tree_state, load_tree)


@dataclass
class InferenceBundle:
    run_dir: Path
    manifest: dict[str, Any]
    schema: FeatureSchema
    preprocessor: NumericPreprocessor
    logistic_classifier: NumericLogistic
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
        manifest = read_json(root / "manifest.json", max_bytes=2 * 1024 * 1024, max_tokens=50_000)
        if type(manifest) is not dict or manifest.get("execution_format") != TABULAR_FORMAT:
            raise ValueError("Unsupported model execution format; numeric artifact version is required")
        verify_artifact_inventory(root, manifest)
        def component(relative: str) -> Any:
            descriptor = manifest["artifacts"][relative]
            return read_json(root / relative, expected_sha256=descriptor["sha256"], expected_bytes=descriptor["bytes"])

        schema_descriptor = manifest["artifacts"]["feature_schema.json"]
        schema = FeatureSchema.from_artifact(root / "feature_schema.json", expected_sha256=schema_descriptor["sha256"], expected_bytes=schema_descriptor["bytes"])
        if schema.hash != manifest.get("feature_schema_hash"):
            raise ValueError("Feature schema hash does not match the registered run manifest.")
        if manifest.get("feature_schema_version") != schema.version:
            raise ValueError("Feature schema version does not match the run manifest")
        _validate_execution_manifest(manifest, schema)
        preprocessor = load_preprocessor(component("preprocessor.json"))
        logistic = load_logistic(component("models/logistic_classifier.json"))
        if preprocessor.feature_names != schema.feature_names or logistic.coefficients.shape[1] != len(schema.feature_names) or logistic.classes_.tolist() != [0, 1]:
            raise ValueError("Numeric classifier/preprocessing schema mismatch")
        calibrators = {
            f"{model}:{method}": load_calibrator(component(f"calibrators/{model}_{method}.json"))
            for model in ("logistic_regression", "xgboost") for method in ("platt", "isotonic")
        }
        if any(model.method != key.split(":")[1] for key, model in calibrators.items()):
            raise ValueError("Numeric calibration role differs from its declared method")
        states = {name: component(f"models/{filename}") for name, filename in [
            ("classifier", "xgboost_classifier.json"), ("margin", "xgboost_home_margin.json"), ("total", "xgboost_total_points.json")
        ]}
        for name, state in states.items():
            validate_tree_state(state, expected_kind="xgboost_binary_classifier" if name == "classifier" else "xgboost_regressor", feature_count=len(schema.feature_names))
        classifier = load_tree(states["classifier"], expected_kind="xgboost_binary_classifier", feature_count=len(schema.feature_names))
        margin = load_tree(states["margin"], expected_kind="xgboost_regressor", feature_count=len(schema.feature_names))
        total = load_tree(states["total"], expected_kind="xgboost_regressor", feature_count=len(schema.feature_names))
        return cls(
            run_dir=root,
            manifest=manifest,
            schema=schema,
            preprocessor=preprocessor,
            logistic_classifier=logistic,
            xgboost_classifier=classifier,
            margin_regressor=margin,
            total_regressor=total,
            calibrators=calibrators,
        )

    def predict(self, frame: pd.DataFrame) -> list[dict[str, Any]]:
        validated = self.schema.validate_inference_frame(frame)
        transformed = self.preprocessor.transform(validated[self.schema.feature_names])
        raw = {
            "logistic_regression": np.asarray(
                self.logistic_classifier.predict_proba(transformed)[:, 1],
                dtype=float,
            ),
            "xgboost": np.asarray(
                self.xgboost_classifier.predict_proba(transformed)[:, 1],
                dtype=float,
            ),
        }
        calibrated = {
            model: self.calibrators[
                f"{model}:{self.manifest['selected_calibrators'][model]}"
            ].predict(probabilities)
            for model, probabilities in raw.items()
        }
        champion = self.manifest["champion_classifier"]
        if champion == "blend":
            anchors, _ = anchor_probabilities(
                validated, self.manifest["classifier_blend"]["anchor_columns"]
            )
            blend = self.manifest["classifier_blend"]
            probabilities = np.clip(
                weighted_probabilities(calibrated, anchors, blend["weights"]),
                blend["probability_floor"],
                1 - blend["probability_floor"],
            )
        else:
            probabilities = calibrated[champion]

        margins = self.margin_regressor.predict(transformed)
        totals = self.total_regressor.predict(transformed)
        outputs: list[dict[str, Any]] = []
        for index, (_, row) in enumerate(validated.iterrows()):
            feature_payload = {
                name: _nullable_float(row[name]) for name in self.schema.feature_names
            }
            disagreement = abs(
                calibrated["logistic_regression"][index]
                - calibrated["xgboost"][index]
            )
            uncertainty = min(
                1.0,
                0.75 * _binary_entropy(float(probabilities[index]))
                + 0.25 * min(1.0, float(disagreement) * 4),
            )
            outputs.append(
                {
                    "home_win_probability": round(float(probabilities[index]), 6),
                    "expected_home_margin": round(float(margins[index]), 4),
                    "expected_total": round(float(totals[index]), 4),
                    "home_cover_probability": _probability_above(
                        threshold=feature_payload.get("feature_market_home_spread"),
                        mean=float(margins[index]),
                        standard_deviation=float(
                            self.manifest["residual_standard_deviation"]["home_margin"]
                        ),
                    ),
                    "over_probability": _probability_above(
                        threshold=feature_payload.get("feature_market_total"),
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


def _validate_execution_manifest(manifest: dict[str, Any], schema: FeatureSchema) -> None:
    classifiers = {"logistic_regression", "xgboost"}
    champion = manifest.get("champion_classifier")
    if type(champion) is not str or champion not in classifiers | {"blend"}:
        raise ValueError("Unsupported manifest classifier selection")
    selected = manifest.get("selected_calibrators")
    if type(selected) is not dict or set(selected) != classifiers or any(type(method) is not str or method not in ("platt", "isotonic") for method in selected.values()):
        raise ValueError("Unsupported manifest calibration selection")
    blend = manifest.get("classifier_blend")
    if type(blend) is not dict:
        raise ValueError("Invalid manifest blend configuration")
    weights = blend.get("weights")
    if type(weights) is not dict or set(weights) != classifiers | {"anchor"}:
        raise ValueError("Invalid manifest blend weights")
    if any(type(value) not in (int, float) or not math.isfinite(value) or not 0 <= value <= 1 for value in weights.values()) or abs(sum(weights.values()) - 1) > 1e-9 or weights["logistic_regression"] + weights["xgboost"] <= 0:
        raise ValueError("Invalid manifest blend weight range")
    anchor_policy = schema.training.get("blend", {})
    if type(anchor_policy) is not dict:
        raise ValueError("Invalid schema anchor policy")
    enabled = anchor_policy.get("enabled", True)
    maximum = anchor_policy.get("maximum_anchor_weight", .7)
    if type(enabled) is not bool or type(maximum) not in (int, float) or not math.isfinite(maximum) or not 0 <= maximum <= 1:
        raise ValueError("Invalid schema anchor policy bounds")
    if (not enabled and weights["anchor"] != 0) or weights["anchor"] > maximum:
        raise ValueError("Manifest anchor policy differs from the consumed schema")
    floor = blend.get("probability_floor")
    if type(floor) not in (int, float) or not math.isfinite(floor) or not 0 < floor < .5:
        raise ValueError("Invalid manifest blend probability floor")
    anchors = blend.get("anchor_columns")
    if type(anchors) is not list or anchors != schema.anchor_columns or len(anchors) > 512 or any(type(name) is not str or name not in schema.feature_names for name in anchors) or len(set(anchors)) != len(anchors):
        raise ValueError("Invalid manifest anchor feature selection")
    residuals = manifest.get("residual_standard_deviation")
    if type(residuals) is not dict or set(residuals) != {"home_margin", "total_points"} or any(type(value) not in (int, float) or not math.isfinite(value) or value <= 0 for value in residuals.values()):
        raise ValueError("Invalid manifest residual standard deviation")
    for field in ("model_run_id", "artifact_id", "model_version", "dataset_hash"):
        if type(manifest.get(field)) is not str or not 1 <= len(manifest[field]) <= 128:
            raise ValueError("Invalid manifest output lineage")
