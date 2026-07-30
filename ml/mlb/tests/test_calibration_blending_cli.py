from __future__ import annotations

import numpy as np
import pytest

from picksports_mlb_ml.blending import (
    select_probability_blend,
    weighted_probabilities,
)
from picksports_mlb_ml.calibration import select_calibrator_method
from picksports_mlb_ml.cli import build_parser
from picksports_mlb_ml.inference import _probability_above


def test_calibrator_comparison_rejects_large_log_loss_regression() -> None:
    comparison = {
        "uncalibrated": {"brier": 0.238, "log_loss": 0.665},
        "platt": {"brier": 0.239, "log_loss": 0.669},
        "isotonic": {"brier": 0.237, "log_loss": 0.79},
    }

    assert select_calibrator_method(comparison) == "platt"


def test_blend_renormalizes_when_optional_anchor_is_missing() -> None:
    probabilities = {
        "logistic_regression": np.array([0.4, 0.7]),
        "xgboost": np.array([0.6, 0.8]),
    }
    result = weighted_probabilities(
        probabilities,
        np.array([0.9, np.nan]),
        {"logistic_regression": 0.2, "xgboost": 0.3, "anchor": 0.5},
    )

    assert result[0] == pytest.approx(0.71)
    assert result[1] == pytest.approx(0.76)


def test_blend_disables_anchor_below_coverage_threshold() -> None:
    targets = np.array([0, 1, 0, 1])
    selected = select_probability_blend(
        {
            "logistic_regression": np.array([0.2, 0.8, 0.3, 0.7]),
            "xgboost": np.array([0.25, 0.75, 0.35, 0.65]),
        },
        np.array([np.nan, np.nan, np.nan, 0.8]),
        targets,
        minimum_anchor_coverage=0.5,
    )

    assert selected["weights"]["anchor"] == 0
    assert selected["anchor_enabled"] is False


def test_home_cover_probability_uses_normalized_home_margin_threshold() -> None:
    home_favorite = _probability_above(
        threshold=1.5,
        mean=0.0,
        standard_deviation=1.0,
    )
    home_underdog = _probability_above(
        threshold=-1.5,
        mean=0.0,
        standard_deviation=1.0,
    )

    assert home_favorite is not None and home_favorite < 0.5
    assert home_underdog is not None and home_underdog > 0.5


def test_cli_lists_operational_commands() -> None:
    help_text = build_parser().format_help()

    assert "validate" in help_text
    assert "train" in help_text
    assert "evaluate-rolling" in help_text
    assert "predict" in help_text
