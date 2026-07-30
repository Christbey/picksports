from __future__ import annotations

from pathlib import Path

import pandas as pd

from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.splits import (
    complete_season_frame,
    season_completeness,
    walk_forward_folds,
)


def test_walk_forward_uses_complete_disjoint_seasons(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, _ = load_immutable_dataset(synthetic_csv, schema)
    folds = walk_forward_folds(frame, schema, minimum_training_seasons=3)

    assert [fold.test_season for fold in folds] == [2022, 2023, 2024]
    for fold in folds:
        assert max(fold.train_seasons) < fold.calibration_season < fold.test_season
        assert set(fold.train["season"]).isdisjoint(fold.calibration["season"])
        assert set(fold.train["season"]).isdisjoint(fold.test["season"])
        assert set(fold.calibration["season"]).isdisjoint(fold.test["season"])


def test_walk_forward_can_limit_training_to_recent_complete_seasons(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, _ = load_immutable_dataset(synthetic_csv, schema)
    folds = walk_forward_folds(
        frame,
        schema,
        minimum_training_seasons=3,
        training_seasons_window=3,
    )

    assert folds[-1].train_seasons == (2020, 2021, 2022)
    assert sorted(folds[-1].train["season"].unique()) == [2020, 2021, 2022]


def test_partial_current_season_is_excluded_from_evaluation_windows(
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    partial = synthetic_frame[synthetic_frame["season"] == 2024].head(4).copy()
    partial["season"] = 2025
    partial["game_id"] = range(
        int(synthetic_frame["game_id"].max()) + 1,
        int(synthetic_frame["game_id"].max()) + 1 + len(partial),
    )
    partial["game_start_at"] = pd.date_range(
        "2025-09-01T18:00:00Z",
        periods=len(partial),
        freq="7D",
    )
    partial["features_available_at"] = partial["game_start_at"] - pd.Timedelta(
        hours=4
    )
    frame = pd.concat([synthetic_frame, partial], ignore_index=True)

    profiles = season_completeness(frame, schema)
    evaluation_frame = complete_season_frame(frame, schema, profiles)
    folds = walk_forward_folds(
        evaluation_frame,
        schema,
        minimum_training_seasons=3,
    )

    assert profiles[-1]["season"] == 2025
    assert profiles[-1]["complete"] is False
    assert profiles[-1]["completion_method"] == "inferred_from_prior_season_coverage"
    assert evaluation_frame["season"].max() == 2024
    assert folds[-1].test_season == 2024
