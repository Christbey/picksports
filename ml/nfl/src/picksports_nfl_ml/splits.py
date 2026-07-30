from __future__ import annotations

from dataclasses import dataclass
from typing import Any

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


def season_completeness(
    frame: pd.DataFrame,
    schema: FeatureSchema,
) -> list[dict[str, Any]]:
    season_column = schema.season_column
    time_column = schema.time_column
    seasons = sorted(int(value) for value in frame[season_column].unique())
    profiles: list[dict[str, Any]] = []
    explicit_column = next(
        (
            column
            for column in ("season_complete", "is_season_complete")
            if column in frame.columns
        ),
        None,
    )

    for season in seasons:
        season_frame = frame[frame[season_column] == season]
        profile: dict[str, Any] = {
            "season": season,
            "row_count": int(len(season_frame)),
            "latest_game_start_at": pd.Timestamp(
                season_frame[time_column].max()
            ).isoformat(),
            "max_week": _maximum_week(season_frame),
        }
        if explicit_column is not None:
            values = _explicit_completion_values(season_frame[explicit_column])
            if len(values) != 1:
                raise ValueError(
                    f"Season {season} has inconsistent {explicit_column} values."
                )
            profile.update(
                {
                    "complete": values[0],
                    "completion_method": f"explicit:{explicit_column}",
                }
            )
        elif season != seasons[-1]:
            profile.update(
                {
                    "complete": True,
                    "completion_method": "superseded_by_later_season",
                }
            )
        else:
            reference = profiles[-3:]
            reference_rows = (
                float(pd.Series([item["row_count"] for item in reference]).median())
                if reference
                else float(profile["row_count"])
            )
            reference_weeks = [
                float(item["max_week"])
                for item in reference
                if item["max_week"] is not None
            ]
            reference_max_week = (
                float(pd.Series(reference_weeks).median())
                if reference_weeks
                else profile["max_week"]
            )
            row_coverage = (
                float(profile["row_count"]) / reference_rows
                if reference_rows > 0
                else 0.0
            )
            week_coverage = (
                float(profile["max_week"]) / reference_max_week
                if profile["max_week"] is not None
                and reference_max_week is not None
                and reference_max_week > 0
                else None
            )
            complete = bool(
                reference
                and row_coverage >= 1.0
                and (week_coverage is None or week_coverage >= 1.0)
            )
            profile.update(
                {
                    "complete": complete,
                    "completion_method": "inferred_from_prior_season_coverage",
                    "row_coverage_vs_prior_median": round(row_coverage, 6),
                    "week_coverage_vs_prior_median": (
                        round(week_coverage, 6)
                        if week_coverage is not None
                        else None
                    ),
                }
            )
        profiles.append(profile)
    return profiles


def complete_season_frame(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    profiles: list[dict[str, Any]] | None = None,
) -> pd.DataFrame:
    resolved_profiles = profiles or season_completeness(frame, schema)
    complete_seasons = {
        int(profile["season"])
        for profile in resolved_profiles
        if bool(profile["complete"])
    }
    return frame[frame[schema.season_column].isin(complete_seasons)].copy()


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


def _maximum_week(frame: pd.DataFrame) -> float | None:
    if "feature_week" not in frame.columns:
        return None
    values = pd.to_numeric(frame["feature_week"], errors="coerce")
    return float(values.max()) if values.notna().any() else None


def _explicit_completion_values(values: pd.Series) -> list[bool]:
    normalized = (
        values.astype(str)
        .str.strip()
        .str.lower()
        .map(
            {
                "1": True,
                "0": False,
                "true": True,
                "false": False,
                "yes": True,
                "no": False,
            }
        )
    )
    if normalized.isna().any():
        raise ValueError("Season completion values must be boolean.")
    return sorted(bool(value) for value in normalized.unique())
