from __future__ import annotations

from itertools import product
from typing import Any

import numpy as np

from picksports_nfl_ml.metrics import classification_metrics


def select_probability_blend(
    model_probabilities: dict[str, np.ndarray],
    baseline_probabilities: np.ndarray,
    targets: np.ndarray,
    max_challenger_weight: float = 0.5,
) -> dict[str, Any]:
    maximum_units = int(round(min(1.0, max(0.0, max_challenger_weight)) * 10))
    best: tuple[tuple[float, float, float, float, float], dict[str, Any]] | None = (
        None
    )
    for logistic_units, xgboost_units in product(range(0, 11), range(0, 11)):
        challenger_units = logistic_units + xgboost_units
        if challenger_units > maximum_units:
            continue
        baseline_units = 10 - challenger_units
        weights = {
            "logistic_regression": logistic_units / 10,
            "xgboost": xgboost_units / 10,
            "current_picksports": baseline_units / 10,
        }
        raw = weighted_probabilities(
            model_probabilities,
            baseline_probabilities,
            weights,
        )
        for probability_floor in (1e-6, 0.01, 0.025, 0.05, 0.075, 0.10):
            probabilities = np.clip(
                raw,
                probability_floor,
                1 - probability_floor,
            )
            metrics = classification_metrics(targets, probabilities)
            rank = (
                metrics["brier"],
                metrics["log_loss"],
                probability_floor,
                -weights["current_picksports"],
                -weights["logistic_regression"],
            )
            candidate = {
                "weights": weights,
                "probability_floor": probability_floor,
                "selection_metrics": metrics,
                "max_challenger_weight": max_challenger_weight,
            }
            if best is None or rank < best[0]:
                best = (rank, candidate)
    if best is None:
        raise RuntimeError("No valid blend candidate was generated.")
    return best[1]


def weighted_probabilities(
    model_probabilities: dict[str, np.ndarray],
    baseline_probabilities: np.ndarray,
    weights: dict[str, float],
) -> np.ndarray:
    return (
        weights["logistic_regression"]
        * model_probabilities["logistic_regression"]
        + weights["xgboost"] * model_probabilities["xgboost"]
        + weights["current_picksports"] * baseline_probabilities
    )
