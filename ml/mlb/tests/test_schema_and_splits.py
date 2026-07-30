from __future__ import annotations

from pathlib import Path

import numpy as np
import pytest

from picksports_mlb_ml.data import load_immutable_dataset
from picksports_mlb_ml.schema import FeatureSchema, SchemaError
from picksports_mlb_ml.splits import (
    chronological_holdout_fold,
    rolling_weekly_folds,
)


def test_trusted_contract_accepts_two_seasons_and_sorts_rows(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(synthetic_csv, schema)

    assert len(frame) == 320
    assert len(dataset_hash) == 64
    assert frame["game_start_at"].is_monotonic_increasing
    assert set(frame["availability_status"]) == {
        "observed_pregame",
        "verified_reconstruction",
    }


def test_rejects_post_start_features(
    synthetic_frame,
    schema_path: Path,
) -> None:
    frame = synthetic_frame.copy()
    frame.loc[0, "features_available_at"] = frame.loc[0, "game_start_at"]
    frame.loc[1, "features_available_at"] = "2027-01-01T00:00:00Z"

    with pytest.raises(SchemaError, match="no later than game start"):
        FeatureSchema.load(schema_path).validate_training_frame(frame)


def test_rejects_untrusted_rows(synthetic_frame, schema_path: Path) -> None:
    frame = synthetic_frame.copy()
    frame.loc[0, "pregame_safe"] = 0

    with pytest.raises(SchemaError, match="pregame safe"):
        FeatureSchema.load(schema_path).validate_training_frame(frame)


def test_optional_market_columns_can_be_absent(
    synthetic_frame,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    row = synthetic_frame.tail(1).drop(
        columns=[
            "feature_market_home_win_probability",
            "feature_market_home_spread",
            "feature_market_total",
        ]
    )

    validated = schema.validate_inference_frame(row)

    assert validated["feature_market_home_win_probability"].isna().all()
    assert validated["feature_market_home_spread"].isna().all()
    assert validated["feature_market_total"].isna().all()


def test_aliases_fill_missing_canonical_values_row_by_row(
    synthetic_frame,
    schema_path: Path,
) -> None:
    frame = synthetic_frame.head(2).copy()
    frame["feature_market_home_spread"] = [1.5, np.nan]
    frame["feature_market_home_margin"] = [9.5, -1.5]

    validated = FeatureSchema.load(schema_path).validate_training_frame(frame)

    assert validated["feature_market_home_spread"].tolist() == [1.5, -1.5]


def test_date_and_week_splits_are_strictly_chronological(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, _ = load_immutable_dataset(synthetic_csv, schema)
    final = chronological_holdout_fold(frame, schema, 2, 2, 80)
    rolling = rolling_weekly_folds(
        frame,
        schema,
        initial_training_weeks=8,
        calibration_weeks=2,
        maximum_windows=3,
        rolling_training_weeks=20,
        minimum_training_rows=80,
    )

    for fold in [final, *rolling]:
        assert fold.train["game_start_at"].max() < fold.calibration["game_start_at"].min()
        assert (
            fold.calibration["game_start_at"].max()
            < fold.test["game_start_at"].min()
        )
        assert set(fold.train["game_id"]).isdisjoint(fold.test["game_id"])
