from __future__ import annotations

from dataclasses import dataclass
from typing import Any

import numpy as np
from sklearn.isotonic import IsotonicRegression
from sklearn.linear_model import LogisticRegression

from picksports_nfl_ml.metrics import classification_metrics


def _logit(probabilities: np.ndarray) -> np.ndarray:
    clipped = np.clip(np.asarray(probabilities, dtype=float), 1e-6, 1 - 1e-6)
    return np.log(clipped / (1 - clipped)).reshape(-1, 1)


@dataclass
class ProbabilityCalibrator:
    method: str
    estimator: Any

    def predict(self, probabilities: np.ndarray) -> np.ndarray:
        values = np.asarray(probabilities, dtype=float)
        if self.method == "platt":
            calibrated = self.estimator.predict_proba(_logit(values))[:, 1]
        elif self.method == "isotonic":
            calibrated = self.estimator.predict(values)
        else:
            raise ValueError(f"Unsupported calibration method: {self.method}")
        return np.clip(calibrated, 1e-6, 1 - 1e-6)


def fit_calibrator(
    method: str,
    probabilities: np.ndarray,
    targets: np.ndarray,
    seed: int,
) -> ProbabilityCalibrator:
    labels = np.asarray(targets, dtype=int)
    if np.unique(labels).size < 2:
        raise ValueError("Calibration data must contain both home wins and losses.")
    if method == "platt":
        estimator = LogisticRegression(random_state=seed, solver="lbfgs")
        estimator.fit(_logit(probabilities), labels)
    elif method == "isotonic":
        estimator = IsotonicRegression(
            y_min=1e-6,
            y_max=1 - 1e-6,
            out_of_bounds="clip",
        )
        estimator.fit(np.asarray(probabilities, dtype=float), labels)
    else:
        raise ValueError(f"Unsupported calibration method: {method}")
    return ProbabilityCalibrator(method=method, estimator=estimator)


def compare_calibrators(
    fit_probabilities: np.ndarray,
    fit_targets: np.ndarray,
    selection_probabilities: np.ndarray,
    selection_targets: np.ndarray,
    seed: int,
    max_log_loss_regression: float = 0.01,
) -> tuple[str, dict[str, dict[str, Any]]]:
    comparison: dict[str, dict[str, Any]] = {
        "uncalibrated": classification_metrics(
            selection_targets, selection_probabilities
        )
    }
    for method in ("platt", "isotonic"):
        calibrator = fit_calibrator(method, fit_probabilities, fit_targets, seed)
        comparison[method] = classification_metrics(
            selection_targets,
            calibrator.predict(selection_probabilities),
        )

    selected = select_calibrator_method(
        comparison,
        max_log_loss_regression=max_log_loss_regression,
    )
    return selected, comparison


def select_calibrator_method(
    comparison: dict[str, dict[str, Any]],
    max_log_loss_regression: float = 0.01,
) -> str:
    baseline_log_loss = float(comparison["uncalibrated"]["log_loss"])
    eligible = [
        method
        for method in ("platt", "isotonic")
        if float(comparison[method]["log_loss"])
        <= baseline_log_loss + max(0.0, max_log_loss_regression)
    ]
    candidates = eligible or ["platt", "isotonic"]
    return min(
        candidates,
        key=lambda method: (
            comparison[method]["brier"],
            comparison[method]["log_loss"],
            method,
        ),
    )
