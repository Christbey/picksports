from __future__ import annotations

from pathlib import Path

from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.splits import walk_forward_folds


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
