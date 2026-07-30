from __future__ import annotations

import json
import math
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
from xgboost import XGBClassifier, XGBRegressor

from picksports_mlb_ml.artifacts import verify_artifact_inventory
from picksports_mlb_ml.blending import anchor_probabilities, weighted_probabilities
from picksports_mlb_ml.calibration import ProbabilityCalibrator
from picksports_mlb_ml.hashing import sha256_json
from picksports_mlb_ml.schema import FeatureSchema


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
    calibrators: dict[str, ProbabilityCalibrator]

    @classmethod
    def load(cls, run_dir: str | Path) -> "InferenceBundle":
        root = Path(run_dir).expanduser().resolve()
        manifest = json.loads((root / "manifest.json").read_text(encoding="utf-8"))
        verify_artifact_inventory(root, manifest)
        schema = FeatureSchema.load(root / "feature_schema.yaml")
        if schema.hash != manifest.get("feature_schema_hash"):
            raise ValueError(
                "Feature schema hash does not match the registered run manifest."
            )
        classifier = XGBClassifier()
        classifier.load_model(root / "models" / "xgboost_classifier.ubj")
        margin = XGBRegressor()
        margin.load_model(root / "models" / "xgboost_home_margin.ubj")
        total = XGBRegressor()
        total.load_model(root / "models" / "xgboost_total_points.ubj")
        calibrators = {
            f"{model}:{method}": joblib.load(
                root / "calibrators" / f"{model}_{method}.joblib"
            )
            for model in ("logistic_regression", "xgboost")
            for method in ("platt", "isotonic")
        }
        return cls(
            run_dir=root,
            manifest=manifest,
            schema=schema,
            preprocessor=joblib.load(root / "preprocessor.joblib"),
            logistic_classifier=joblib.load(
                root / "models" / "logistic_classifier.joblib"
            ),
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
