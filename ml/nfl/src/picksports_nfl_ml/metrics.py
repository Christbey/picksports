from __future__ import annotations

import math
from typing import Any

import numpy as np
from sklearn.metrics import (
    accuracy_score,
    brier_score_loss,
    log_loss,
    mean_absolute_error,
    mean_squared_error,
    roc_auc_score,
)


def classification_metrics(
    targets: np.ndarray,
    probabilities: np.ndarray,
) -> dict[str, Any]:
    clipped = np.clip(np.asarray(probabilities, dtype=float), 1e-6, 1 - 1e-6)
    labels = np.asarray(targets, dtype=int)
    metrics: dict[str, Any] = {
        "count": int(labels.size),
        "accuracy": float(accuracy_score(labels, clipped >= 0.5)),
        "brier": float(brier_score_loss(labels, clipped)),
        "log_loss": float(log_loss(labels, clipped, labels=[0, 1])),
    }
    metrics["roc_auc"] = (
        float(roc_auc_score(labels, clipped)) if np.unique(labels).size == 2 else None
    )
    return metrics


def regression_metrics(
    targets: np.ndarray,
    predictions: np.ndarray,
) -> dict[str, Any]:
    actual = np.asarray(targets, dtype=float)
    predicted = np.asarray(predictions, dtype=float)
    return {
        "count": int(actual.size),
        "mae": float(mean_absolute_error(actual, predicted)),
        "rmse": float(math.sqrt(mean_squared_error(actual, predicted))),
        "residual_std": float(np.std(actual - predicted, ddof=1))
        if actual.size > 1
        else 0.0,
    }
