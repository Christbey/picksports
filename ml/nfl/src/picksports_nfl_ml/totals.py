from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd

from picksports_nfl_ml.metrics import regression_metrics
from picksports_nfl_ml.schema import FeatureSchema


STRATEGY = "baseline_residual_blend"


def total_model_config(schema: FeatureSchema) -> dict[str, Any]:
    configured = dict(schema.training.get("total_model", {}))
    return {
        "strategy": str(configured.get("strategy", STRATEGY)),
        "baseline_feature": str(
            configured.get(
                "baseline_feature",
                "feature_model_predicted_total",
            )
        ),
        "max_residual_weight": min(
            1.0,
            max(0.0, float(configured.get("max_residual_weight", 0.35))),
        ),
        "weight_step": min(
            1.0,
            max(0.01, float(configured.get("weight_step", 0.05))),
        ),
        "max_abs_adjustment": max(
            0.0,
            float(configured.get("max_abs_adjustment", 4.0)),
        ),
        "minimum_selection_mae_improvement": max(
            0.0,
            float(
                configured.get(
                    "minimum_selection_mae_improvement",
                    0.10,
                )
            ),
        ),
    }


def total_baseline_values(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    fallback: float,
) -> np.ndarray:
    config = total_model_config(schema)
    feature = config["baseline_feature"]
    if feature not in frame.columns:
        return np.full(len(frame), fallback, dtype=float)

    values = pd.to_numeric(frame[feature], errors="coerce")
    values = values.replace([np.inf, -np.inf], np.nan).fillna(fallback)
    return values.to_numpy(dtype=float)


def total_residual_targets(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    fallback: float,
) -> np.ndarray:
    actual = frame[schema.target_columns["total_points"]].to_numpy(dtype=float)
    return actual - total_baseline_values(frame, schema, fallback)


def blended_total_predictions(
    frame: pd.DataFrame,
    residual_predictions: np.ndarray,
    schema: FeatureSchema,
    selection: dict[str, Any],
) -> np.ndarray:
    fallback = float(selection["baseline_fallback"])
    baseline = total_baseline_values(frame, schema, fallback)
    weight = float(selection["selected_residual_weight"])
    maximum_adjustment = float(selection["max_abs_adjustment"])
    correction = np.asarray(residual_predictions, dtype=float) * weight
    correction = np.clip(
        correction,
        -maximum_adjustment,
        maximum_adjustment,
    )
    return baseline + correction


def select_total_residual_blend(
    frame: pd.DataFrame,
    residual_predictions: np.ndarray,
    schema: FeatureSchema,
    baseline_fallback: float,
) -> dict[str, Any]:
    config = total_model_config(schema)
    actual = frame[schema.target_columns["total_points"]].to_numpy(dtype=float)
    baseline = total_baseline_values(frame, schema, baseline_fallback)
    residual_predictions = np.asarray(residual_predictions, dtype=float)
    baseline_metrics = regression_metrics(actual, baseline)
    candidates: list[dict[str, Any]] = []

    for weight in _candidate_weights(
        config["max_residual_weight"],
        config["weight_step"],
    ):
        predictions = blended_total_predictions(
            frame,
            residual_predictions,
            schema,
            {
                "baseline_fallback": baseline_fallback,
                "selected_residual_weight": weight,
                "max_abs_adjustment": config["max_abs_adjustment"],
            },
        )
        candidates.append(
            {
                "residual_weight": weight,
                **regression_metrics(actual, predictions),
            }
        )

    best = min(
        candidates,
        key=lambda candidate: (
            candidate["mae"],
            candidate["rmse"],
            candidate["residual_weight"],
        ),
    )
    improvement = float(baseline_metrics["mae"] - best["mae"])
    if improvement < config["minimum_selection_mae_improvement"]:
        best = candidates[0]
        improvement = 0.0

    return {
        "strategy": STRATEGY,
        "baseline_feature": config["baseline_feature"],
        "baseline_fallback": float(baseline_fallback),
        "selected_residual_weight": float(best["residual_weight"]),
        "max_residual_weight": config["max_residual_weight"],
        "weight_step": config["weight_step"],
        "max_abs_adjustment": config["max_abs_adjustment"],
        "minimum_selection_mae_improvement": config[
            "minimum_selection_mae_improvement"
        ],
        "selection_rows": int(len(frame)),
        "selection_baseline_metrics": baseline_metrics,
        "selection_metrics": {
            key: value
            for key, value in best.items()
            if key != "residual_weight"
        },
        "selection_mae_improvement": improvement,
        "candidates": candidates,
    }


def _candidate_weights(maximum: float, step: float) -> list[float]:
    weights = [0.0]
    current = step
    while current < maximum - 1e-12:
        weights.append(round(current, 10))
        current += step
    if maximum > 0:
        weights.append(round(maximum, 10))
    return sorted(set(weights))
