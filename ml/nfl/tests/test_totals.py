from __future__ import annotations

from pathlib import Path

import numpy as np
import pandas as pd

from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.totals import (
    blended_total_predictions,
    select_total_residual_blend,
)


def test_retains_picksports_total_when_residual_model_does_not_improve(
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame = _frame(
        baseline=[40.0, 44.0, 48.0, 52.0],
        actual=[40.5, 43.5, 48.5, 51.5],
    )
    residual_predictions = np.array([-12.0, 12.0, -12.0, 12.0])

    selection = select_total_residual_blend(
        frame,
        residual_predictions,
        schema,
        baseline_fallback=45.0,
    )
    predictions = blended_total_predictions(
        frame,
        residual_predictions,
        schema,
        selection,
    )

    assert selection["selected_residual_weight"] == 0.0
    assert selection["selection_mae_improvement"] == 0.0
    np.testing.assert_allclose(
        predictions,
        frame["feature_model_predicted_total"].to_numpy(dtype=float),
    )


def test_selects_only_a_capped_chronological_total_correction(
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame = _frame(
        baseline=[40.0, 44.0, 48.0, 52.0],
        actual=[42.0, 46.0, 50.0, 54.0],
    )
    residual_predictions = np.full(len(frame), 20.0)

    selection = select_total_residual_blend(
        frame,
        residual_predictions,
        schema,
        baseline_fallback=45.0,
    )
    predictions = blended_total_predictions(
        frame,
        residual_predictions,
        schema,
        selection,
    )
    adjustments = (
        predictions
        - frame["feature_model_predicted_total"].to_numpy(dtype=float)
    )

    assert 0 < selection["selected_residual_weight"] <= 0.35
    assert selection["selection_mae_improvement"] > 0
    assert np.max(np.abs(adjustments)) <= 4.0


def _frame(baseline: list[float], actual: list[float]) -> pd.DataFrame:
    return pd.DataFrame(
        {
            "feature_model_predicted_total": baseline,
            "target_total_points": actual,
        }
    )
