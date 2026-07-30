from __future__ import annotations

import copy
import json
from pathlib import Path
from typing import Any, Iterable

import numpy as np
import pandas as pd

from picksports_nfl_ml.calibration import compare_calibrators, fit_calibrator
from picksports_nfl_ml.blending import (
    select_probability_blend,
    weighted_probabilities,
)
from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.hashing import sha256_json
from picksports_nfl_ml.metrics import classification_metrics, regression_metrics
from picksports_nfl_ml.models import (
    classifier_probabilities,
    fit_model_set,
    transformed_features,
)
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.splits import (
    ChronologicalFold,
    calibration_selection_split,
    walk_forward_folds,
)
from picksports_nfl_ml.totals import (
    blended_total_predictions,
    select_total_residual_blend,
)


DELTA_CONVENTION = "baseline_minus_challenger"
FEATURE_GROUPS: dict[str, tuple[str, ...]] = {
    "baseline_derived": (
        "feature_model_",
        "feature_confidence_score",
    ),
    "qb_form": ("feature_qb_form__",),
    "rolling_efficiency": ("feature_rolling_efficiency__",),
    "line_matchup": ("feature_line_matchup__",),
    "contextual": ("feature_contextual_factors__",),
    "roster_grades": ("feature_player_position_grades__",),
    "market": ("feature_market_",),
}


def diagnose(
    input_path: str | Path,
    schema_path: str | Path,
    output_path: str | Path,
    target_season: int = 2024,
    expected_dataset_sha256: str | None = None,
    seed: int | None = None,
    training_windows: Iterable[int | None] = (None, 4, 5, 6),
    run_ablations: bool = True,
) -> dict[str, Any]:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(
        input_path,
        schema,
        expected_dataset_sha256,
    )
    effective_seed = int(seed if seed is not None else schema.training["seed"])
    calibration_fraction = float(schema.training["calibration_fit_fraction"])
    minimum_seasons = int(schema.training["minimum_training_seasons"])
    folds = walk_forward_folds(frame, schema, minimum_seasons)
    target_fold = _fold_for_season(folds, target_season)
    target_result = _evaluate_fold_with_blend(
        target_fold,
        schema,
        effective_seed,
        calibration_fraction,
        include_rows=True,
    )

    report: dict[str, Any] = {
        "report_type": "nfl_failure_diagnostic_and_ablation",
        "delta_convention": DELTA_CONVENTION,
        "positive_delta_means": "challenger_better",
        "dataset": {
            "path": str(Path(input_path).expanduser().resolve()),
            "sha256": dataset_hash,
            "rows": int(len(frame)),
            "seasons": sorted(
                int(value) for value in frame[schema.season_column].unique()
            ),
        },
        "feature_schema": {
            "version": schema.version,
            "sha256": schema.hash,
            "feature_count": len(schema.feature_names),
        },
        "target_season": target_season,
        "target_window": _without_private_rows(target_result),
        "failure_diagnostics": _failure_diagnostics(
            target_fold,
            schema,
            target_result["_rows"],
        ),
        "feature_group_ablations": (
            _run_ablations(
                folds,
                target_season,
                schema,
                effective_seed,
                calibration_fraction,
                target_result,
            )
            if run_ablations
            else {"enabled": False, "groups": []}
        ),
        "training_window_comparison": _training_window_comparison(
            folds,
            schema,
            effective_seed,
            calibration_fraction,
            training_windows,
        ),
        "recommendations": _recommendations(target_result),
    }

    destination = Path(output_path).expanduser().resolve()
    destination.parent.mkdir(parents=True, exist_ok=True)
    with destination.open("w", encoding="utf-8") as handle:
        json.dump(
            report,
            handle,
            indent=2,
            sort_keys=True,
            default=_json_default,
        )
        handle.write("\n")

    return {
        "report_path": str(destination),
        "dataset_hash": dataset_hash,
        "target_season": target_season,
        "blend": target_result["win_probability"]["blend"],
        "market_deltas": target_result["market_deltas"],
    }


def _evaluate_fold_with_blend(
    fold: ChronologicalFold,
    schema: FeatureSchema,
    seed: int,
    calibration_fraction: float,
    include_rows: bool = False,
) -> dict[str, Any]:
    models = fit_model_set(fold.train, schema, seed)
    fit_frame, selection_frame = calibration_selection_split(
        fold.calibration,
        schema,
        calibration_fraction,
    )
    fit_transformed = transformed_features(models, fit_frame, schema)
    selection_transformed = transformed_features(models, selection_frame, schema)
    calibration_transformed = transformed_features(models, fold.calibration, schema)
    test_transformed = transformed_features(models, fold.test, schema)
    target_column = schema.target_columns["home_win"]
    fit_targets = fit_frame[target_column].to_numpy(dtype=int)
    selection_targets = selection_frame[target_column].to_numpy(dtype=int)
    calibration_targets = fold.calibration[target_column].to_numpy(dtype=int)
    test_targets = fold.test[target_column].to_numpy(dtype=int)

    selection_probabilities: dict[str, np.ndarray] = {}
    test_probabilities: dict[str, np.ndarray] = {}
    calibrator_choices: dict[str, Any] = {}
    for model_name in ("logistic_regression", "xgboost"):
        fit_raw = classifier_probabilities(models, model_name, fit_transformed)
        selection_raw = classifier_probabilities(
            models,
            model_name,
            selection_transformed,
        )
        selected_method, comparison = compare_calibrators(
            fit_raw,
            fit_targets,
            selection_raw,
            selection_targets,
            seed,
        )
        selection_calibrator = fit_calibrator(
            selected_method,
            fit_raw,
            fit_targets,
            seed,
        )
        selection_probabilities[model_name] = selection_calibrator.predict(
            selection_raw
        )
        full_raw = classifier_probabilities(
            models,
            model_name,
            calibration_transformed,
        )
        final_calibrator = fit_calibrator(
            selected_method,
            full_raw,
            calibration_targets,
            seed,
        )
        test_probabilities[model_name] = final_calibrator.predict(
            classifier_probabilities(models, model_name, test_transformed)
        )
        calibrator_choices[model_name] = {
            "selected_method": selected_method,
            "selection_metrics": comparison,
        }

    train_home_rate = float(fold.train[target_column].mean())
    selection_baseline = (
        pd.to_numeric(
            selection_frame["feature_model_win_probability"],
            errors="coerce",
        )
        .fillna(train_home_rate)
        .to_numpy(dtype=float)
    )
    test_baseline = (
        pd.to_numeric(
            fold.test["feature_model_win_probability"],
            errors="coerce",
        )
        .fillna(train_home_rate)
        .to_numpy(dtype=float)
    )
    blend = select_probability_blend(
        selection_probabilities,
        selection_baseline,
        selection_targets,
    )
    blend_raw_test = weighted_probabilities(
        test_probabilities,
        test_baseline,
        blend["weights"],
    )
    blend_test = np.clip(
        blend_raw_test,
        blend["probability_floor"],
        1 - blend["probability_floor"],
    )
    classifier_metrics = {
        name: classification_metrics(test_targets, probabilities)
        for name, probabilities in test_probabilities.items()
    }
    baseline_metrics = classification_metrics(test_targets, test_baseline)
    blend_metrics = classification_metrics(test_targets, blend_test)

    margin_predictions = models.margin_regressor.predict(test_transformed)
    total_model = select_total_residual_blend(
        fold.calibration,
        models.total_regressor.predict(calibration_transformed),
        schema,
        models.total_baseline_fallback,
    )
    total_predictions = blended_total_predictions(
        fold.test,
        models.total_regressor.predict(test_transformed),
        schema,
        total_model,
    )
    margin_baseline = pd.to_numeric(
        fold.test["feature_model_predicted_spread"],
        errors="coerce",
    ).to_numpy(dtype=float)
    total_baseline = pd.to_numeric(
        fold.test["feature_model_predicted_total"],
        errors="coerce",
    ).to_numpy(dtype=float)
    margin_metrics = regression_metrics(
        fold.test[schema.target_columns["home_margin"]].to_numpy(dtype=float),
        margin_predictions,
    )
    total_metrics = regression_metrics(
        fold.test[schema.target_columns["total_points"]].to_numpy(dtype=float),
        total_predictions,
    )
    margin_baseline_metrics = regression_metrics(
        fold.test[schema.target_columns["home_margin"]].to_numpy(dtype=float),
        margin_baseline,
    )
    total_baseline_metrics = regression_metrics(
        fold.test[schema.target_columns["total_points"]].to_numpy(dtype=float),
        total_baseline,
    )
    result: dict[str, Any] = {
        "train_seasons": list(fold.train_seasons),
        "calibration_season": fold.calibration_season,
        "test_season": fold.test_season,
        "games": int(len(fold.test)),
        "feature_count": len(schema.feature_names),
        "feature_schema_hash": schema.hash,
        "calibrators": calibrator_choices,
        "win_probability": {
            "current_picksports": baseline_metrics,
            "logistic_regression": classifier_metrics["logistic_regression"],
            "xgboost": classifier_metrics["xgboost"],
            "blend": {
                **blend_metrics,
                "weights": blend["weights"],
                "probability_floor": blend["probability_floor"],
                "selection_metrics": blend["selection_metrics"],
                "confidence_cap_sensitivity": [
                    {
                        "probability_floor": probability_floor,
                        **classification_metrics(
                            test_targets,
                            np.clip(
                                blend_raw_test,
                                probability_floor,
                                1 - probability_floor,
                            ),
                        ),
                    }
                    for probability_floor in (
                        1e-6,
                        0.01,
                        0.025,
                        0.05,
                        0.075,
                        0.10,
                    )
                ],
            },
        },
        "home_margin": {
            "current_picksports": margin_baseline_metrics,
            "xgboost": margin_metrics,
        },
        "total_points": {
            "current_picksports": total_baseline_metrics,
            "xgboost": total_metrics,
            "selection": total_model,
        },
        "market_deltas": {
            "win_probability": {
                "brier": baseline_metrics["brier"] - blend_metrics["brier"],
                "log_loss": baseline_metrics["log_loss"]
                - blend_metrics["log_loss"],
            },
            "home_margin": {
                "mae": margin_baseline_metrics["mae"] - margin_metrics["mae"],
            },
            "total_points": {
                "mae": total_baseline_metrics["mae"] - total_metrics["mae"],
            },
        },
    }
    if include_rows:
        week_column = (
            "week" if "week" in fold.test.columns else "feature_week"
        )
        rows = fold.test[
            [
                schema.id_column,
                schema.season_column,
                week_column,
                schema.target_columns["home_win"],
            ]
        ].copy()
        rows = rows.rename(columns={week_column: "week"})
        rows["baseline_probability"] = test_baseline
        rows["logistic_probability"] = test_probabilities["logistic_regression"]
        rows["xgboost_probability"] = test_probabilities["xgboost"]
        rows["blend_probability"] = blend_test
        result["_rows"] = rows
    return result


def _run_ablations(
    folds: list[ChronologicalFold],
    target_season: int,
    schema: FeatureSchema,
    seed: int,
    calibration_fraction: float,
    full_result: dict[str, Any],
) -> dict[str, Any]:
    groups: list[dict[str, Any]] = []
    full_brier = full_result["win_probability"]["blend"]["brier"]
    full_log_loss = full_result["win_probability"]["blend"]["log_loss"]
    for group_name, prefixes in FEATURE_GROUPS.items():
        removed = [
            feature
            for feature in schema.feature_names
            if any(feature.startswith(prefix) for prefix in prefixes)
        ]
        if not removed or len(removed) == len(schema.feature_names):
            continue
        ablated_schema = _schema_without(schema, removed)
        results = [
            _evaluate_fold_with_blend(
                fold,
                ablated_schema,
                seed,
                calibration_fraction,
            )
            for fold in folds
        ]
        result = next(
            item for item in results if item["test_season"] == target_season
        )
        groups.append(
            {
                "group": group_name,
                "removed_feature_count": len(removed),
                "removed_features": removed,
                "feature_schema_hash": ablated_schema.hash,
                "blend": result["win_probability"]["blend"],
                "market_deltas": result["market_deltas"],
                "target_season_change_vs_full": {
                    "brier": full_brier
                    - result["win_probability"]["blend"]["brier"],
                    "log_loss": full_log_loss
                    - result["win_probability"]["blend"]["log_loss"],
                },
                "chronological_summary": _summarize_strategy(results),
                "windows": [
                    {
                        "test_season": item["test_season"],
                        "blend": item["win_probability"]["blend"],
                        "market_deltas": item["market_deltas"],
                    }
                    for item in results
                ],
            }
        )
    groups.sort(
        key=lambda item: (
            item["chronological_summary"]["average_brier_improvement"],
            item["chronological_summary"]["average_log_loss_improvement"],
        ),
        reverse=True,
    )
    return {
        "enabled": True,
        "interpretation": (
            "Positive target_season_change_vs_full means removal improved the "
            "target season. Chronological summary compares the ablated blend "
            "with Picksports in every held-out season."
        ),
        "groups": groups,
    }


def _training_window_comparison(
    folds: list[ChronologicalFold],
    schema: FeatureSchema,
    seed: int,
    calibration_fraction: float,
    training_windows: Iterable[int | None],
) -> dict[str, Any]:
    strategies: list[dict[str, Any]] = []
    seen: set[int | None] = set()
    for requested_window in training_windows:
        window = None if requested_window in (None, 0) else int(requested_window)
        if window in seen:
            continue
        seen.add(window)
        results: list[dict[str, Any]] = []
        for fold in folds:
            if window is not None and len(fold.train_seasons) < window:
                continue
            evaluation_fold = _limit_fold(fold, schema, window)
            result = _evaluate_fold_with_blend(
                evaluation_fold,
                schema,
                seed,
                calibration_fraction,
            )
            results.append(result)
        strategies.append(
            {
                "strategy": "expanding" if window is None else f"rolling_{window}",
                "training_seasons": window,
                "windows": results,
                "summary": _summarize_strategy(results),
            }
        )
    strategies.sort(
        key=lambda item: (
            -item["summary"]["average_brier_improvement"]
            if item["summary"]["window_count"]
            else float("inf"),
            -item["summary"]["average_log_loss_improvement"]
            if item["summary"]["window_count"]
            else float("inf"),
        )
    )
    return {
        "strategies": strategies,
        "recommended_strategy": (
            strategies[0]["strategy"] if strategies else None
        ),
    }


def _failure_diagnostics(
    fold: ChronologicalFold,
    schema: FeatureSchema,
    rows: pd.DataFrame,
) -> dict[str, Any]:
    segments: dict[str, list[dict[str, Any]]] = {}
    week = pd.to_numeric(rows["week"], errors="coerce")
    segments["season_phase"] = _segment_metrics(
        rows,
        pd.cut(
            week,
            bins=[0, 4, 9, 14, np.inf],
            labels=["weeks_1_4", "weeks_5_9", "weeks_10_14", "weeks_15_plus"],
        ),
        schema.target_columns["home_win"],
    )
    segments["blend_probability_bucket"] = _segment_metrics(
        rows,
        pd.cut(
            rows["blend_probability"],
            bins=[0, 0.4, 0.5, 0.6, 1],
            include_lowest=True,
            labels=["0_40", "40_50", "50_60", "60_100"],
        ),
        schema.target_columns["home_win"],
    )
    segments["baseline_side"] = _segment_metrics(
        rows,
        pd.Series(
            np.where(
                rows["baseline_probability"] >= 0.5,
                "baseline_home",
                "baseline_away",
            ),
            index=rows.index,
        ),
        schema.target_columns["home_win"],
    )

    target = rows[schema.target_columns["home_win"]].to_numpy(dtype=int)
    predicted = (rows["blend_probability"].to_numpy(dtype=float) >= 0.5).astype(
        int
    )
    incorrect = rows.loc[predicted != target].copy()
    incorrect["confidence"] = (
        incorrect["blend_probability"] - 0.5
    ).abs() * 2
    confident_errors = (
        incorrect.sort_values("confidence", ascending=False)
        .head(25)
        .to_dict(orient="records")
    )

    drift: list[dict[str, Any]] = []
    for feature in schema.feature_names:
        train_values = pd.to_numeric(fold.train[feature], errors="coerce")
        test_values = pd.to_numeric(fold.test[feature], errors="coerce")
        train_std = float(train_values.std(ddof=0))
        train_mean = float(train_values.mean()) if train_values.notna().any() else 0.0
        test_mean = float(test_values.mean()) if test_values.notna().any() else 0.0
        standardized_shift = (
            abs(test_mean - train_mean) / train_std if train_std > 1e-9 else 0.0
        )
        drift.append(
            {
                "feature": feature,
                "standardized_mean_shift": standardized_shift,
                "train_missing_rate": float(train_values.isna().mean()),
                "test_missing_rate": float(test_values.isna().mean()),
                "missing_rate_delta": float(
                    test_values.isna().mean() - train_values.isna().mean()
                ),
            }
        )
    drift.sort(
        key=lambda item: (
            item["standardized_mean_shift"],
            abs(item["missing_rate_delta"]),
        ),
        reverse=True,
    )
    return {
        "segments": segments,
        "confident_errors": confident_errors,
        "top_feature_drift": drift[:25],
        "limitations": [
            "The trusted export does not contain team labels, so team-level errors require joining by game_id in Laravel.",
            "Historical point-in-time moneyline prices are unavailable in this dataset, so ROI and CLV are not estimated here.",
        ],
    }


def _segment_metrics(
    rows: pd.DataFrame,
    groups: pd.Series,
    target_column: str,
) -> list[dict[str, Any]]:
    output: list[dict[str, Any]] = []
    for label in groups.dropna().unique():
        mask = groups == label
        segment = rows.loc[mask]
        if segment.empty:
            continue
        targets = segment[target_column].to_numpy(dtype=int)
        baseline = classification_metrics(
            targets,
            segment["baseline_probability"].to_numpy(dtype=float),
        )
        challenger = classification_metrics(
            targets,
            segment["blend_probability"].to_numpy(dtype=float),
        )
        output.append(
            {
                "segment": str(label),
                "games": int(len(segment)),
                "baseline": baseline,
                "challenger": challenger,
                "brier_improvement": baseline["brier"] - challenger["brier"],
                "log_loss_improvement": baseline["log_loss"]
                - challenger["log_loss"],
            }
        )
    return output


def _summarize_strategy(results: list[dict[str, Any]]) -> dict[str, Any]:
    if not results:
        return {
            "window_count": 0,
            "better_window_count": 0,
            "average_brier_improvement": 0.0,
            "average_log_loss_improvement": 0.0,
            "worst_brier_regression": None,
            "worst_log_loss_regression": None,
        }
    brier = [
        result["market_deltas"]["win_probability"]["brier"]
        for result in results
    ]
    log_loss = [
        result["market_deltas"]["win_probability"]["log_loss"]
        for result in results
    ]
    return {
        "window_count": len(results),
        "better_window_count": sum(
            brier_delta > 0 and log_loss_delta > 0
            for brier_delta, log_loss_delta in zip(brier, log_loss, strict=True)
        ),
        "average_brier_improvement": float(np.mean(brier)),
        "average_log_loss_improvement": float(np.mean(log_loss)),
        "worst_brier_regression": max(0.0, -min(brier)),
        "worst_log_loss_regression": max(0.0, -min(log_loss)),
    }


def _schema_without(schema: FeatureSchema, removed: list[str]) -> FeatureSchema:
    raw = copy.deepcopy(schema.raw)
    raw["features"] = {
        name: spec
        for name, spec in raw["features"].items()
        if name not in set(removed)
    }
    raw["schema_version"] = (
        f"{schema.version}-ablation-{sha256_json(sorted(removed))[:10]}"
    )
    return FeatureSchema(path=schema.path, raw=raw)


def _limit_fold(
    fold: ChronologicalFold,
    schema: FeatureSchema,
    training_seasons: int | None,
) -> ChronologicalFold:
    if training_seasons is None:
        return fold
    selected = fold.train_seasons[-training_seasons:]
    return ChronologicalFold(
        train=fold.train[
            fold.train[schema.season_column].isin(selected)
        ].copy(),
        calibration=fold.calibration,
        test=fold.test,
        train_seasons=selected,
        calibration_season=fold.calibration_season,
        test_season=fold.test_season,
    )


def _fold_for_season(
    folds: list[ChronologicalFold],
    target_season: int,
) -> ChronologicalFold:
    for fold in folds:
        if fold.test_season == target_season:
            return fold
    available = ", ".join(str(fold.test_season) for fold in folds)
    raise ValueError(
        f"Season {target_season} is not an available test window. "
        f"Available seasons: {available}."
    )


def _without_private_rows(result: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in result.items() if not key.startswith("_")}


def _recommendations(result: dict[str, Any]) -> list[str]:
    recommendations = [
        "Use the blend only as a challenger until chronological and live-shadow gates pass.",
        "Prefer the training-window strategy with positive average Brier and log-loss improvement and an acceptable worst window.",
        "Remove feature groups only when ablation improvement repeats across multiple held-out seasons.",
    ]
    if result["market_deltas"]["total_points"]["mae"] <= 0:
        recommendations.append(
            "Keep the total-points model frozen because it did not beat the current Picksports total in the target window."
        )
    return recommendations


def _json_default(value: Any) -> Any:
    if isinstance(value, np.generic):
        return value.item()
    if isinstance(value, pd.Timestamp):
        return value.isoformat()
    raise TypeError(f"Object of type {type(value).__name__} is not JSON serializable.")
