from __future__ import annotations

from dataclasses import dataclass

import pandas as pd

from picksports_mlb_ml.schema import FeatureSchema


@dataclass(frozen=True)
class ChronologicalFold:
    train: pd.DataFrame
    calibration: pd.DataFrame
    test: pd.DataFrame
    train_start_at: str
    train_end_at: str
    calibration_start_at: str
    calibration_end_at: str
    test_start_at: str
    test_end_at: str


def chronological_holdout_fold(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    calibration_weeks: int,
    test_weeks: int,
    minimum_training_rows: int,
) -> ChronologicalFold:
    weeks = _observed_weeks(frame, schema)
    if len(weeks) <= calibration_weeks + test_weeks:
        raise ValueError(
            "Chronological holdout requires training weeks plus separate "
            "calibration and test weeks."
        )
    calibration_start = len(weeks) - calibration_weeks - test_weeks
    return _fold_from_week_indexes(
        frame,
        schema,
        weeks,
        train_indexes=range(0, calibration_start),
        calibration_indexes=range(
            calibration_start, calibration_start + calibration_weeks
        ),
        test_indexes=range(calibration_start + calibration_weeks, len(weeks)),
        minimum_training_rows=minimum_training_rows,
    )


def rolling_weekly_folds(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    initial_training_weeks: int,
    calibration_weeks: int,
    test_weeks: int = 1,
    rolling_training_weeks: int | None = None,
    maximum_windows: int | None = None,
    minimum_training_rows: int = 100,
) -> list[ChronologicalFold]:
    weeks = _observed_weeks(frame, schema)
    first_test = initial_training_weeks + calibration_weeks
    folds: list[ChronologicalFold] = []
    for test_start in range(first_test, len(weeks) - test_weeks + 1, test_weeks):
        calibration_start = test_start - calibration_weeks
        train_start = (
            max(0, calibration_start - rolling_training_weeks)
            if rolling_training_weeks
            else 0
        )
        try:
            fold = _fold_from_week_indexes(
                frame,
                schema,
                weeks,
                train_indexes=range(train_start, calibration_start),
                calibration_indexes=range(calibration_start, test_start),
                test_indexes=range(test_start, test_start + test_weeks),
                minimum_training_rows=minimum_training_rows,
            )
        except ValueError:
            continue
        folds.append(fold)
    if maximum_windows is not None:
        folds = folds[-maximum_windows:]
    if not folds:
        raise ValueError("No valid rolling weekly evaluation windows were produced.")
    return folds


def calibration_selection_split(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    fit_fraction: float,
) -> tuple[pd.DataFrame, pd.DataFrame]:
    if not 0.4 <= fit_fraction <= 0.8:
        raise ValueError("Calibration fit fraction must be between 0.4 and 0.8.")
    ordered = frame.sort_values([schema.time_column, schema.id_column], kind="stable")
    if len(ordered) < 8:
        raise ValueError("Calibration data requires at least eight games.")
    split_at = min(max(int(len(ordered) * fit_fraction), 4), len(ordered) - 4)
    return ordered.iloc[:split_at].copy(), ordered.iloc[split_at:].copy()


def _observed_weeks(frame: pd.DataFrame, schema: FeatureSchema) -> list[pd.Timestamp]:
    timestamps = frame[schema.time_column].dt.tz_convert("UTC").dt.tz_localize(None)
    return sorted(timestamps.dt.to_period("W-SUN").dt.start_time.unique())


def _fold_from_week_indexes(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    weeks: list[pd.Timestamp],
    train_indexes: range,
    calibration_indexes: range,
    test_indexes: range,
    minimum_training_rows: int,
) -> ChronologicalFold:
    week_values = (
        frame[schema.time_column]
        .dt.tz_convert("UTC")
        .dt.tz_localize(None)
        .dt.to_period("W-SUN")
        .dt.start_time
    )
    train = frame[week_values.isin([weeks[index] for index in train_indexes])].copy()
    calibration = frame[
        week_values.isin([weeks[index] for index in calibration_indexes])
    ].copy()
    test = frame[week_values.isin([weeks[index] for index in test_indexes])].copy()
    if len(train) < minimum_training_rows:
        raise ValueError(
            f"Training window has {len(train)} rows; {minimum_training_rows} required."
        )
    if calibration.empty or test.empty:
        raise ValueError("Calibration and test windows must not be empty.")
    if train[schema.target_columns["home_win"]].nunique() < 2:
        raise ValueError("Training window must contain home wins and losses.")
    if calibration[schema.target_columns["home_win"]].nunique() < 2:
        raise ValueError("Calibration window must contain home wins and losses.")
    return ChronologicalFold(
        train=train,
        calibration=calibration,
        test=test,
        train_start_at=_iso(train[schema.time_column].min()),
        train_end_at=_iso(train[schema.time_column].max()),
        calibration_start_at=_iso(calibration[schema.time_column].min()),
        calibration_end_at=_iso(calibration[schema.time_column].max()),
        test_start_at=_iso(test[schema.time_column].min()),
        test_end_at=_iso(test[schema.time_column].max()),
    )


def _iso(value: pd.Timestamp) -> str:
    return value.isoformat()
