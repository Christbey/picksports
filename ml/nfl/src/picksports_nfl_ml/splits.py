from __future__ import annotations

from dataclasses import dataclass

import pandas as pd

from picksports_nfl_ml.schema import FeatureSchema


@dataclass(frozen=True)
class ChronologicalFold:
    train: pd.DataFrame
    calibration: pd.DataFrame
    test: pd.DataFrame
    train_seasons: tuple[int, ...]
    calibration_season: int
    test_season: int


def walk_forward_folds(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    minimum_training_seasons: int,
    training_seasons_window: int | None = None,
) -> list[ChronologicalFold]:
    season_column = schema.season_column
    seasons = tuple(sorted(int(value) for value in frame[season_column].unique()))
    if len(seasons) < minimum_training_seasons + 2:
        raise ValueError(
            "Walk-forward evaluation requires training seasons plus separate "
            "calibration and test seasons."
        )

    folds: list[ChronologicalFold] = []
    for test_index in range(minimum_training_seasons + 1, len(seasons)):
        calibration_season = seasons[test_index - 1]
        test_season = seasons[test_index]
        available_train_seasons = seasons[: test_index - 1]
        train_seasons = (
            available_train_seasons[-training_seasons_window:]
            if training_seasons_window is not None
            else available_train_seasons
        )
        folds.append(
            ChronologicalFold(
                train=frame[frame[season_column].isin(train_seasons)].copy(),
                calibration=frame[frame[season_column] == calibration_season].copy(),
                test=frame[frame[season_column] == test_season].copy(),
                train_seasons=train_seasons,
                calibration_season=calibration_season,
                test_season=test_season,
            )
        )
    return folds


def final_holdout_fold(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    minimum_training_seasons: int,
    final_test_seasons: int,
    training_seasons_window: int | None = None,
) -> ChronologicalFold:
    if final_test_seasons != 1:
        raise ValueError(
            "Version 1 saves one evaluated artifact and therefore requires "
            "final_test_seasons=1."
        )
    folds = walk_forward_folds(
        frame,
        schema,
        minimum_training_seasons,
        training_seasons_window,
    )
    return folds[-1]


def calibration_selection_split(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    fit_fraction: float,
) -> tuple[pd.DataFrame, pd.DataFrame]:
    if not 0.4 <= fit_fraction <= 0.8:
        raise ValueError("Calibration fit fraction must be between 0.4 and 0.8.")
    ordered = frame.sort_values([schema.time_column, schema.id_column], kind="stable")
    split_at = int(len(ordered) * fit_fraction)
    split_at = min(max(split_at, 2), len(ordered) - 2)
    fit = ordered.iloc[:split_at].copy()
    selection = ordered.iloc[split_at:].copy()
    if fit.empty or selection.empty:
        raise ValueError("Calibration season is too small to split chronologically.")
    return fit, selection
