from types import SimpleNamespace

import numpy as np
import pytest

from picksports_nfl_ml import pipeline
from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.metrics import classification_metrics
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.splits import walk_forward_folds


class NoFitElo:
    def __init__(self, **kwargs):
        pass

    def fit(self, *args):
        return self

    def predict_proba(self, values):
        return np.tile([0.5, 0.5], (len(values), 1))


class IdentityCalibrator:
    def predict(self, values):
        return values


@pytest.mark.parametrize("coverage", ["partial", "complete", "absent", "missing_pair", "mismatched_pair"])
@pytest.mark.parametrize("champion_name", ["logistic_regression", "xgboost", "blend"])
def test_market_comparison_uses_the_same_observed_games(
    tmp_path, monkeypatch, synthetic_frame, schema_path, coverage, champion_name,
):
    # Substitute inference outputs and Elo fitting; use actual admission,
    # chronological folds, evaluation, baseline metrics and promotion comparison.
    monkeypatch.setattr(pipeline, "LogisticRegression", NoFitElo)
    schema = FeatureSchema.load(schema_path)
    frame = synthetic_frame.copy()
    ids = frame.index[frame.season == 2024]
    actual = np.array([1, 0] * 8)
    frame.loc[ids, "target_home_win"] = actual
    frame.loc[ids, "target_home_margin"] = np.where(actual == 1, 7.0, -7.0)
    frame.loc[ids, "feature_market_home_spread"] = np.where(actual == 1, 1.0, -1.0)
    if coverage in ["partial", "missing_pair", "mismatched_pair"]:
        frame.loc[ids[2:], "feature_market_home_spread"] = np.nan
    if coverage == "absent":
        frame.loc[ids, "feature_market_home_spread"] = np.nan
    path = tmp_path / "valid.csv"
    frame.to_csv(path, index=False)
    admitted, _ = load_immutable_dataset(path, schema)
    fold = walk_forward_folds(admitted, schema, 3)[-1]
    probabilities = np.where(actual == 1, 1.0, 0.0)
    probabilities[:2] = [0.01, 0.99]
    monkeypatch.setattr(
        pipeline, "transformed_features",
        lambda *args: np.zeros((len(fold.test), 1)),
    )
    monkeypatch.setattr(
        pipeline, "classifier_probabilities", lambda *args: probabilities.copy(),
    )
    monkeypatch.setattr(
        pipeline, "blended_total_predictions",
        lambda test, *args: test[schema.target_columns["total_points"]].to_numpy(),
    )
    models = SimpleNamespace(
        margin_regressor=SimpleNamespace(
            predict=lambda values: fold.test[schema.target_columns["home_margin"]].to_numpy(),
        ),
        total_regressor=SimpleNamespace(
            predict=lambda values: fold.test[schema.target_columns["total_points"]].to_numpy(),
        ),
    )
    calibration = {
        name: {
            "selected_method": "controlled_identity",
            "calibrators": {"controlled_identity": IdentityCalibrator()},
            "selection_metrics": {},
        }
        for name in pipeline.CLASSIFIERS
    }
    blend = {
        "weights": {"logistic_regression": 1.0, "xgboost": 0.0, "current_picksports": 0.0},
        "probability_floor": 1e-6,
        "max_challenger_weight": 1.0,
        "selection_metrics": {},
    }
    evaluation = pipeline._evaluate_saved_model(
        models, calibration, fold, schema, blend, {},
    )
    if coverage == "missing_pair":
        evaluation["classifiers"][champion_name].pop("test_market_cohort", None)
    elif coverage == "mismatched_pair":
        evaluation["classifiers"][champion_name]["test_market_cohort"] = {
            "available": True, "count": 999, "brier": 0.0, "log_loss": 0.0,
        }
    window = {**evaluation, "champion_classifier": champion_name}
    comparison = pipeline._promotion_summary([window] * 3)["baseline_comparisons"][
        "market_favorite"
    ]
    valid = fold.test.feature_market_home_spread.notna().to_numpy()
    if not valid.any() or coverage in ["missing_pair", "mismatched_pair"]:
        assert comparison["available"] is False
        return
    paired = classification_metrics(actual[valid], probabilities[valid])
    reference = evaluation["baselines"]["classifiers"]["market_favorite"]
    expected_wins = 3 if paired["brier"] < reference["brier"] else 0
    assert comparison["wins"] == expected_wins
    assert comparison["avg_brier_delta"] == pytest.approx(
        reference["brier"] - paired["brier"]
    )
    assert comparison["avg_log_loss_delta"] == pytest.approx(
        reference["log_loss"] - paired["log_loss"]
    )
