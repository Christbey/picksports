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

from picksports_nfl_ml.blending import weighted_probabilities
from picksports_nfl_ml.calibration import ProbabilityCalibrator
from picksports_nfl_ml.hashing import sha256_json
from picksports_nfl_ml.schema import FeatureSchema


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
        with (root / "manifest.json").open("r", encoding="utf-8") as handle:
            manifest = json.load(handle)
        schema = FeatureSchema.load(root / "feature_schema.yaml")
        preprocessor = joblib.load(root / "preprocessor.joblib")
        logistic = joblib.load(root / "models" / "logistic_classifier.joblib")

        xgb_classifier = XGBClassifier()
        xgb_classifier.load_model(root / "models" / "xgboost_classifier.ubj")
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
        totals = self.total_regressor.predict(transformed)
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
