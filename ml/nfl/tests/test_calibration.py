from __future__ import annotations

from picksports_nfl_ml.calibration import select_calibrator_method


def test_rejects_small_brier_gain_with_large_log_loss_regression() -> None:
    comparison = {
        "uncalibrated": {"brier": 0.2380, "log_loss": 0.6650},
        "platt": {"brier": 0.2395, "log_loss": 0.6690},
        "isotonic": {"brier": 0.2381, "log_loss": 0.7560},
    }

    assert select_calibrator_method(comparison) == "platt"


def test_keeps_isotonic_when_brier_gain_does_not_damage_log_loss() -> None:
    comparison = {
        "uncalibrated": {"brier": 0.2450, "log_loss": 0.6900},
        "platt": {"brier": 0.2400, "log_loss": 0.6800},
        "isotonic": {"brier": 0.2350, "log_loss": 0.6750},
    }

    assert select_calibrator_method(comparison) == "isotonic"
