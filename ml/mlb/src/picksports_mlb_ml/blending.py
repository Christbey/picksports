from __future__ import annotations

from itertools import product
from typing import Any

import numpy as np
import pandas as pd

from picksports_mlb_ml.metrics import classification_metrics


def anchor_probabilities(
    frame: pd.DataFrame,
    columns: list[str],
) -> tuple[np.ndarray, float]:
    available = [column for column in columns if column in frame]
    if not available:
        return np.full(len(frame), np.nan), 0.0
    values = frame[available].apply(pd.to_numeric, errors="coerce").to_numpy(float)
    counts = np.sum(np.isfinite(values), axis=1)
    totals = np.nansum(values, axis=1)
    anchors = np.divide(
        totals,
        counts,
        out=np.full(len(frame), np.nan),
        where=counts > 0,
    )
    return np.clip(anchors, 1e-6, 1 - 1e-6), float(np.mean(counts > 0))


def select_probability_blend(
    model_probabilities: dict[str, np.ndarray],
    anchors: np.ndarray,
    targets: np.ndarray,
    enabled: bool = True,
    minimum_anchor_coverage: float = 0.5,
    maximum_anchor_weight: float = 0.7,
) -> dict[str, Any]:
    anchor_coverage = float(np.mean(np.isfinite(anchors)))
    anchor_limit = (
        int(round(maximum_anchor_weight * 10))
        if enabled and anchor_coverage >= minimum_anchor_coverage
        else 0
    )
    best: tuple[tuple[float, float, float, float], dict[str, Any]] | None = None
    for logistic_units, xgboost_units in product(range(11), range(11)):
        anchor_units = 10 - logistic_units - xgboost_units
        if anchor_units < 0 or anchor_units > anchor_limit:
            continue
        weights = {
            "logistic_regression": logistic_units / 10,
            "xgboost": xgboost_units / 10,
            "anchor": anchor_units / 10,
        }
        if weights["logistic_regression"] + weights["xgboost"] == 0:
            continue
        raw = weighted_probabilities(model_probabilities, anchors, weights)
        for floor in (1e-6, 0.01, 0.025, 0.05, 0.075, 0.10):
            metrics = classification_metrics(
                targets, np.clip(raw, floor, 1 - floor)
            )
            rank = (
                metrics["brier"],
                metrics["log_loss"],
                floor,
                -weights["anchor"],
            )
            candidate = {
                "weights": weights,
                "probability_floor": floor,
                "selection_metrics": metrics,
                "anchor_coverage": anchor_coverage,
                "anchor_enabled": anchor_limit > 0,
            }
            if best is None or rank < best[0]:
                best = (rank, candidate)
    if best is None:
        raise RuntimeError("No valid probability blend was generated.")
    return best[1]


def weighted_probabilities(
    model_probabilities: dict[str, np.ndarray],
    anchors: np.ndarray,
    weights: dict[str, float],
) -> np.ndarray:
    logistic = np.asarray(model_probabilities["logistic_regression"], dtype=float)
    xgboost = np.asarray(model_probabilities["xgboost"], dtype=float)
    anchor_values = np.asarray(anchors, dtype=float)
    numerator = (
        weights["logistic_regression"] * logistic
        + weights["xgboost"] * xgboost
    )
    denominator = np.full(len(logistic), weights["logistic_regression"] + weights["xgboost"])
    available = np.isfinite(anchor_values)
    numerator[available] += weights["anchor"] * anchor_values[available]
    denominator[available] += weights["anchor"]
    return numerator / denominator
