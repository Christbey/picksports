from __future__ import annotations

from typing import Any

import numpy as np
import pandas as pd
import shap

from picksports_nfl_ml.models import ModelSet, transformed_features
from picksports_nfl_ml.schema import FeatureSchema


def explain_xgboost_models(
    models: ModelSet,
    frame: pd.DataFrame,
    schema: FeatureSchema,
    seed: int,
    config: dict[str, Any],
) -> tuple[dict[str, Any], pd.DataFrame, dict[str, Any]]:
    enabled = bool(config.get("enabled", True))
    if not enabled:
        return (
            {"enabled": False, "status": "disabled", "models": {}},
            pd.DataFrame(
                columns=[
                    "model",
                    "game_id",
                    "feature",
                    "feature_value",
                    "shap_value",
                ]
            ),
            {},
        )

    max_rows = max(1, int(config.get("max_rows", 256)))
    sampled = frame.sample(
        n=min(max_rows, len(frame)),
        random_state=seed,
        replace=False,
    ).sort_values([schema.time_column, schema.id_column], kind="stable")
    transformed = transformed_features(models, sampled, schema)
    estimators = {
        "xgboost_classifier": models.xgboost_classifier,
        "xgboost_home_margin": models.margin_regressor,
        "xgboost_total_points": models.total_regressor,
    }
    summary: dict[str, Any] = {
        "enabled": True,
        "status": "completed",
        "sample_rows": int(len(sampled)),
        "sample_strategy": "deterministic_random_training_sample",
        "models": {},
    }
    native: dict[str, Any] = {}
    value_rows: list[dict[str, Any]] = []
    game_ids = sampled[schema.id_column].tolist()
    for model_name, estimator in estimators.items():
        explainer = shap.TreeExplainer(estimator)
        values = np.asarray(explainer.shap_values(transformed), dtype=float)
        if values.ndim == 3:
            values = values[:, :, -1]
        mean_absolute = np.mean(np.abs(values), axis=0)
        mean_signed = np.mean(values, axis=0)
        ranked = sorted(
            (
                {
                    "feature": feature,
                    "mean_absolute_shap": float(mean_absolute[index]),
                    "mean_shap": float(mean_signed[index]),
                }
                for index, feature in enumerate(schema.feature_names)
            ),
            key=lambda item: (-item["mean_absolute_shap"], item["feature"]),
        )
        summary["models"][model_name] = {
            "expected_value": _json_value(explainer.expected_value),
            "features": ranked,
        }
        booster_scores = estimator.get_booster().get_score(importance_type="gain")
        native[model_name] = {
            feature: float(booster_scores.get(f"f{index}", 0.0))
            for index, feature in enumerate(schema.feature_names)
        }
        for row_index, game_id in enumerate(game_ids):
            for feature_index, feature in enumerate(schema.feature_names):
                value_rows.append(
                    {
                        "model": model_name,
                        "game_id": game_id,
                        "feature": feature,
                        "feature_value": float(transformed[row_index, feature_index]),
                        "shap_value": float(values[row_index, feature_index]),
                    }
                )
    return summary, pd.DataFrame(value_rows), native


def _json_value(value: Any) -> float | list[float]:
    array = np.asarray(value, dtype=float)
    if array.ndim == 0:
        return float(array)
    return [float(item) for item in array.ravel()]
