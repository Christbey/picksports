from __future__ import annotations

import numpy as np
import pytest

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


def test_actual_optimizer_never_rounds_a_non_tenth_maximum_up() -> None:
    targets = np.array([0, 1] * 10)
    exact = np.where(targets == 1, .99, .01)
    selected = select_probability_blend({'logistic_regression': exact, 'xgboost': exact}, np.full(len(targets), .5), targets, max_challenger_weight=.66)
    assert selected['weights']['logistic_regression'] + selected['weights']['xgboost'] <= .66
    assert selected['weights']['current_picksports'] >= .34


@pytest.mark.parametrize('maximum', [-.1, 1.1, float('nan'), float('inf'), True, '0.5', 10**500])
def test_optimizer_refuses_invalid_maximum_before_candidate_generation(maximum) -> None:
    with pytest.raises(ValueError, match='maximum'):
        select_probability_blend({'logistic_regression': np.array([.9, .1]), 'xgboost': np.array([.9, .1])}, np.array([.5, .5]), np.array([1, 0]), max_challenger_weight=maximum)
