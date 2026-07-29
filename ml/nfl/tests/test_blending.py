from __future__ import annotations

import numpy as np

from picksports_nfl_ml.blending import select_probability_blend


def test_probability_blend_keeps_baseline_as_majority_anchor() -> None:
    targets = np.array([1, 0, 1, 0, 1, 0], dtype=int)
    blend = select_probability_blend(
        {
            "logistic_regression": np.array(
                [0.9, 0.1, 0.8, 0.2, 0.9, 0.1],
                dtype=float,
            ),
            "xgboost": np.array(
                [0.95, 0.05, 0.9, 0.1, 0.95, 0.05],
                dtype=float,
            ),
        },
        np.full(6, 0.5, dtype=float),
        targets,
        max_challenger_weight=0.5,
    )

    challenger_weight = (
        blend["weights"]["logistic_regression"]
        + blend["weights"]["xgboost"]
    )
    assert challenger_weight <= 0.5
    assert blend["weights"]["current_picksports"] >= 0.5
