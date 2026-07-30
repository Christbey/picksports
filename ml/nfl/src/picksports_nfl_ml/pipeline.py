from __future__ import annotations

import hashlib
import importlib.metadata
import json
import os
import subprocess
import uuid
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
from sklearn.linear_model import LogisticRegression

from picksports_nfl_ml.artifacts import save_run_artifacts
from picksports_nfl_ml.blending import (
    select_probability_blend,
    weighted_probabilities,
)
from picksports_nfl_ml.calibration import (
    ProbabilityCalibrator,
    compare_calibrators,
    fit_calibrator,
)
from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.explanations import explain_xgboost_models
from picksports_nfl_ml.hashing import sha256_file, sha256_json
from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.metrics import classification_metrics, regression_metrics
from picksports_nfl_ml.models import (
    ModelSet,
    classifier_probabilities,
    fit_model_set,
    transformed_features,
)
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.splits import (
    ChronologicalFold,
    calibration_selection_split,
    complete_season_frame,
    final_holdout_fold,
    season_completeness,
    walk_forward_folds,
)
from picksports_nfl_ml.tuning import tune_xgboost_models
from picksports_nfl_ml.totals import (
    blended_total_predictions,
    select_total_residual_blend,
)


CLASSIFIERS = ("logistic_regression", "xgboost")
CLASSIFIER_CANDIDATES = (*CLASSIFIERS, "blend")
CALIBRATORS = ("platt", "isotonic")


def train(
    input_path: str | Path,
    schema_path: str | Path,
    output_dir: str | Path,
    expected_dataset_sha256: str | None = None,
    run_id: str | None = None,
    seed: int | None = None,
    tuning_enabled: bool | None = None,
    tuning_trials: int | None = None,
    tuning_timeout_seconds: int | None = None,
    explanations_enabled: bool | None = None,
    shap_max_rows: int | None = None,
) -> dict[str, Any]:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(
        input_path,
        schema,
        expected_dataset_sha256,
    )
    effective_seed = int(seed if seed is not None else schema.training["seed"])
    _set_deterministic_seed(effective_seed)
    minimum_seasons = int(schema.training["minimum_training_seasons"])
    training_seasons_window = schema.training.get("training_seasons_window")
    if training_seasons_window is not None:
        training_seasons_window = int(training_seasons_window)
    calibration_fraction = float(schema.training["calibration_fit_fraction"])
    final_test_seasons = int(schema.training["final_test_seasons"])
    season_profiles = season_completeness(frame, schema)
    evaluation_frame = complete_season_frame(frame, schema, season_profiles)
    folds = walk_forward_folds(
        evaluation_frame,
        schema,
        minimum_seasons,
        training_seasons_window,
    )

    walk_forward_results = [
        _evaluate_fold(fold, schema, effective_seed, calibration_fraction)
        for fold in folds
    ]
    final_fold = final_holdout_fold(
        evaluation_frame,
        schema,
        minimum_seasons,
        final_test_seasons,
        training_seasons_window,
    )
    tuning_config = _effective_tuning_config(
        schema,
        tuning_enabled,
        tuning_trials,
        tuning_timeout_seconds,
    )
    explanation_config = _effective_explanation_config(
        schema,
        explanations_enabled,
        shap_max_rows,
    )
    tuned_parameters, tuning_provenance = tune_xgboost_models(
        final_fold.train,
        schema,
        effective_seed,
        tuning_config,
    )
    models = fit_model_set(
        final_fold.train,
        schema,
        effective_seed,
        tuned_parameters=tuned_parameters,
    )
    final_calibration = _calibrate_models(
        models,
        final_fold.calibration,
        schema,
        effective_seed,
        calibration_fraction,
    )
    champion, classifier_blend = _select_champion(
        final_calibration,
        final_fold.calibration,
        schema,
        calibration_fraction,
    )
    total_model = _select_total_model(
        models,
        final_fold.calibration,
        schema,
    )
    final_metrics = _evaluate_saved_model(
        models,
        final_calibration,
        final_fold,
        schema,
        classifier_blend,
        total_model,
    )
    residuals = _calibration_residual_standard_deviations(
        models,
        final_fold.calibration,
        schema,
        total_model,
    )
    deployment_models = fit_model_set(
        frame,
        schema,
        effective_seed,
        tuned_parameters=tuned_parameters,
    )
    deployment_refit = _deployment_refit_provenance(
        frame=frame,
        schema=schema,
        season_profiles=season_profiles,
        final_fold=final_fold,
        final_calibration=final_calibration,
        classifier_blend=classifier_blend,
        champion=champion,
        total_model=total_model,
    )
    explanation_summary, explanation_values, native_importance = (
        explain_xgboost_models(
            deployment_models,
            frame,
            schema,
            effective_seed,
            explanation_config,
        )
    )

    model_run_id = run_id or str(uuid.uuid4())
    artifact_id = str(uuid.uuid4())
    source_hash = _source_hash()
    config_hash = sha256_json(
        {
            "schema": schema.raw,
            "effective_seed": effective_seed,
            "tuning": tuning_config,
            "explanations": explanation_config,
            "package_version": _package_version(),
            "source_hash": source_hash,
        }
    )
    generated_at = datetime.now(timezone.utc).isoformat()
    manifest: dict[str, Any] = {
        "manifest_version": 1,
        "model_type": "nfl_tabular_bundle",
        "model_version": "nfl-tabular-v3",
        "blend_version": "baseline-anchored-v2",
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "generated_at": generated_at,
        "dataset_hash": dataset_hash,
        "feature_schema_version": schema.version,
        "feature_schema_hash": schema.hash,
        "config_hash": config_hash,
        "code_version": _code_version(),
        "source_hash": source_hash,
        "seed": effective_seed,
        "champion_classifier": champion,
        "classifier_blend": classifier_blend,
        "total_model": total_model,
        "selected_calibrators": {
            model_name: final_calibration[model_name]["selected_method"]
            for model_name in CLASSIFIERS
        },
        "residual_standard_deviation": residuals,
        "training_seasons": list(final_fold.train_seasons),
        "evaluation_training_seasons": list(final_fold.train_seasons),
        "artifact_training_seasons": deployment_refit["seasons"],
        "artifact_training_cutoff": deployment_refit["training_cutoff"],
        "training_seasons_window": training_seasons_window,
        "calibration_season": final_fold.calibration_season,
        "held_out_test_season": final_fold.test_season,
        "held_out_test_season_status": "complete",
        "deployment_refit": deployment_refit,
        "dependencies": _dependency_versions(),
        "tuned_parameters": tuned_parameters,
    }
    evaluation = {
        "report_type": "nfl_tabular_walk_forward_evaluation",
        "generated_at": generated_at,
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "dataset": {
            "path": str(Path(input_path).expanduser().resolve()),
            "sha256": dataset_hash,
            "rows": int(len(frame)),
            "seasons": sorted(int(value) for value in frame[schema.season_column].unique()),
            "season_completeness": season_profiles,
        },
        "feature_schema": {
            "version": schema.version,
            "sha256": schema.hash,
            "features": schema.feature_names,
        },
        "selection_policy": {
            "tuning": (
                "Optuna, when enabled, tunes each XGBoost estimator on an inner "
                "chronological split made only from pre-refit evaluation training "
                "seasons. Calibration and held-out test seasons are inaccessible."
            ),
            "calibration": (
                "Fit Platt and isotonic on the first chronological calibration "
                "segment; reject candidates whose validation log loss regresses "
                "materially, then choose by Brier and log loss on the later "
                "segment; refit the chosen candidates on the complete "
                "calibration season."
            ),
            "classifier": (
                "Choose logistic regression, XGBoost, or a baseline-anchored "
                "blend by validation-selection Brier then log loss. The blend "
                "cannot assign more than the configured weight to challengers. "
                "Held-out test metrics never affect selection."
            ),
            "total_points": (
                "Fit XGBoost to residuals from the Picksports total, then "
                "select a capped correction weight on the chronological "
                "calibration season. A zero weight retains the existing "
                "Picksports total when residual learning does not clear the "
                "configured MAE improvement threshold."
            ),
        },
        "final_holdout": {
            "train_seasons": list(final_fold.train_seasons),
            "calibration_season": final_fold.calibration_season,
            "test_season": final_fold.test_season,
            "test_season_status": "complete",
            "champion_classifier": champion,
            "total_model": total_model,
            **final_metrics,
        },
        "walk_forward": {
            "windows": walk_forward_results,
            "summary": _summarize_walk_forward(walk_forward_results),
        },
        "tuning": {
            "enabled": bool(tuning_provenance["enabled"]),
            "status": tuning_provenance["status"],
            "fit_seasons": tuning_provenance.get("fit_seasons", []),
            "validation_seasons": tuning_provenance.get(
                "validation_seasons", []
            ),
            "test_season_access": False,
            "studies": {
                name: {
                    "objective_metric": study["objective_metric"],
                    "best_value": study["best_value"],
                    "best_parameters": study["best_parameters"],
                    "trial_count": study["trial_count"],
                }
                for name, study in tuning_provenance.get("studies", {}).items()
            },
        },
        "deployment_refit": deployment_refit,
    }
    evaluation["promotion_summary"] = _promotion_summary(walk_forward_results)

    example_frame = final_fold.test.iloc[[0]].copy()
    calibrator_objects = {
        model_name: final_calibration[model_name]["calibrators"]
        for model_name in CLASSIFIERS
    }
    run_dir = save_run_artifacts(
        output_root=output_dir,
        manifest=manifest,
        evaluation=evaluation,
        example_output={},
        models=deployment_models,
        calibrators=calibrator_objects,
        schema=schema,
        tuning_provenance=tuning_provenance,
        explanation_summary=explanation_summary,
        explanation_values=explanation_values,
        native_feature_importance=native_importance,
    )
    example_output = InferenceBundle.load(run_dir).predict(example_frame)[0]
    with (run_dir / "prediction_example.json").open("w", encoding="utf-8") as handle:
        json.dump(example_output, handle, indent=2, sort_keys=True)
        handle.write("\n")

    # Refresh the example hash in the immutable file inventory.
    with (run_dir / "manifest.json").open("r", encoding="utf-8") as handle:
        saved_manifest = json.load(handle)
    example_path = run_dir / "prediction_example.json"
    saved_manifest["artifacts"]["prediction_example.json"] = {
        "sha256": sha256_file(example_path),
        "bytes": example_path.stat().st_size,
    }
    with (run_dir / "manifest.json").open("w", encoding="utf-8") as handle:
        json.dump(saved_manifest, handle, indent=2, sort_keys=True)
        handle.write("\n")

    return {
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "run_dir": str(run_dir),
        "dataset_hash": dataset_hash,
        "config_hash": config_hash,
        "champion_classifier": champion,
        "held_out_test_season": final_fold.test_season,
        "artifact_training_cutoff": deployment_refit["training_cutoff"],
        "evaluation_path": str(run_dir / "evaluation.json"),
        "manifest_path": str(run_dir / "manifest.json"),
        "prediction_example_path": str(run_dir / "prediction_example.json"),
    }


def _evaluate_fold(
    fold: ChronologicalFold,
    schema: FeatureSchema,
    seed: int,
    calibration_fraction: float,
) -> dict[str, Any]:
    models = fit_model_set(fold.train, schema, seed)
    calibration = _calibrate_models(
        models, fold.calibration, schema, seed, calibration_fraction
    )
    champion, classifier_blend = _select_champion(
        calibration,
        fold.calibration,
        schema,
        calibration_fraction,
    )
    total_model = _select_total_model(models, fold.calibration, schema)
    metrics = _evaluate_saved_model(
        models,
        calibration,
        fold,
        schema,
        classifier_blend,
        total_model,
    )
    return {
        "train_seasons": list(fold.train_seasons),
        "calibration_season": fold.calibration_season,
        "test_season": fold.test_season,
        "champion_classifier": champion,
        "classifier_blend": classifier_blend,
        "total_model": total_model,
        **metrics,
    }


def _calibrate_models(
    models: ModelSet,
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
    seed: int,
    calibration_fraction: float,
) -> dict[str, dict[str, Any]]:
    fit_frame, selection_frame = calibration_selection_split(
        calibration_frame, schema, calibration_fraction
    )
    fit_transformed = transformed_features(models, fit_frame, schema)
    selection_transformed = transformed_features(models, selection_frame, schema)
    full_transformed = transformed_features(models, calibration_frame, schema)
    target_column = schema.target_columns["home_win"]
    result: dict[str, dict[str, Any]] = {}
    for model_name in CLASSIFIERS:
        fit_probabilities = classifier_probabilities(
            models, model_name, fit_transformed
        )
        selection_probabilities = classifier_probabilities(
            models, model_name, selection_transformed
        )
        selected_method, comparison = compare_calibrators(
            fit_probabilities,
            fit_frame[target_column].to_numpy(dtype=int),
            selection_probabilities,
            selection_frame[target_column].to_numpy(dtype=int),
            seed,
        )
        full_probabilities = classifier_probabilities(
            models, model_name, full_transformed
        )
        calibrators = {
            method: fit_calibrator(
                method,
                full_probabilities,
                calibration_frame[target_column].to_numpy(dtype=int),
                seed,
            )
            for method in CALIBRATORS
        }
        result[model_name] = {
            "selected_method": selected_method,
            "selection_metrics": comparison,
            "selection_probabilities": fit_calibrator(
                selected_method,
                fit_probabilities,
                fit_frame[target_column].to_numpy(dtype=int),
                seed,
            ).predict(selection_probabilities),
            "calibrators": calibrators,
        }
    return result


def _select_champion(
    calibration: dict[str, dict[str, Any]],
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
    calibration_fraction: float,
) -> tuple[str, dict[str, Any]]:
    _, selection_frame = calibration_selection_split(
        calibration_frame,
        schema,
        calibration_fraction,
    )
    target_column = schema.target_columns["home_win"]
    baseline = pd.to_numeric(
        selection_frame["feature_model_win_probability"],
        errors="coerce",
    ).fillna(float(calibration_frame[target_column].mean()))
    blend_config = dict(schema.training.get("blend", {}))
    blend = select_probability_blend(
        {
            model_name: calibration[model_name]["selection_probabilities"]
            for model_name in CLASSIFIERS
        },
        baseline.to_numpy(dtype=float),
        selection_frame[target_column].to_numpy(dtype=int),
        max_challenger_weight=float(
            blend_config.get("max_challenger_weight", 0.5)
        ),
    )
    candidates = {
        model_name: calibration[model_name]["selection_metrics"][
            calibration[model_name]["selected_method"]
        ]
        for model_name in CLASSIFIERS
    }
    candidates["blend"] = blend["selection_metrics"]
    champion = min(
        candidates,
        key=lambda model_name: (
            candidates[model_name]["brier"],
            candidates[model_name]["log_loss"],
            model_name,
        ),
    )
    return champion, blend


def _evaluate_saved_model(
    models: ModelSet,
    calibration: dict[str, dict[str, Any]],
    fold: ChronologicalFold,
    schema: FeatureSchema,
    classifier_blend: dict[str, Any],
    total_model: dict[str, Any],
) -> dict[str, Any]:
    transformed = transformed_features(models, fold.test, schema)
    home_win_targets = fold.test[schema.target_columns["home_win"]].to_numpy(
        dtype=int
    )
    classifier_results: dict[str, Any] = {}
    calibrated_probabilities: dict[str, np.ndarray] = {}
    for model_name in CLASSIFIERS:
        raw = classifier_probabilities(models, model_name, transformed)
        selected_method = calibration[model_name]["selected_method"]
        calibrated = calibration[model_name]["calibrators"][selected_method].predict(raw)
        calibrated_probabilities[model_name] = calibrated
        classifier_results[model_name] = {
            "selected_calibrator": selected_method,
            "calibration_selection": calibration[model_name]["selection_metrics"],
            "test_uncalibrated": classification_metrics(home_win_targets, raw),
            "test_calibrated": classification_metrics(home_win_targets, calibrated),
        }
    baseline = pd.to_numeric(
        fold.test["feature_model_win_probability"],
        errors="coerce",
    ).fillna(float(fold.train[schema.target_columns["home_win"]].mean()))
    blend_probabilities = np.clip(
        weighted_probabilities(
            calibrated_probabilities,
            baseline.to_numpy(dtype=float),
            classifier_blend["weights"],
        ),
        classifier_blend["probability_floor"],
        1 - classifier_blend["probability_floor"],
    )
    blend_metrics = classification_metrics(home_win_targets, blend_probabilities)
    classifier_results["blend"] = {
        "selected_calibrator": "weighted_blend",
        "weights": classifier_blend["weights"],
        "probability_floor": classifier_blend["probability_floor"],
        "max_challenger_weight": classifier_blend["max_challenger_weight"],
        "calibration_selection": classifier_blend["selection_metrics"],
        "test_uncalibrated": blend_metrics,
        "test_calibrated": blend_metrics,
    }

    margin_predictions = models.margin_regressor.predict(transformed)
    total_predictions = blended_total_predictions(
        fold.test,
        models.total_regressor.predict(transformed),
        schema,
        total_model,
    )
    return {
        "classifiers": classifier_results,
        "regressors": {
            "home_margin": regression_metrics(
                fold.test[schema.target_columns["home_margin"]].to_numpy(dtype=float),
                margin_predictions,
            ),
            "total_points": regression_metrics(
                fold.test[schema.target_columns["total_points"]].to_numpy(dtype=float),
                total_predictions,
            ),
        },
        "baselines": _evaluate_baselines(fold, schema),
    }


def _evaluate_baselines(
    fold: ChronologicalFold,
    schema: FeatureSchema,
) -> dict[str, Any]:
    target = schema.target_columns["home_win"]
    actual = fold.test[target].to_numpy(dtype=int)
    home_rate = float(fold.train[target].mean())
    baseline_probabilities: dict[str, np.ndarray] = {
        "home_rate": np.full(len(fold.test), home_rate, dtype=float),
    }

    elo = LogisticRegression(random_state=0, solver="lbfgs", max_iter=1000)
    elo.fit(
        fold.train[["feature_elo_diff"]].to_numpy(dtype=float),
        fold.train[target].to_numpy(dtype=int),
    )
    baseline_probabilities["elo_only"] = elo.predict_proba(
        fold.test[["feature_elo_diff"]].to_numpy(dtype=float)
    )[:, 1]

    current = pd.to_numeric(
        fold.test["feature_model_win_probability"], errors="coerce"
    ).fillna(home_rate)
    baseline_probabilities["current_picksports"] = current.to_numpy(dtype=float)

    classifier_metrics = {
        name: {
            "available": True,
            **classification_metrics(actual, probabilities),
        }
        for name, probabilities in baseline_probabilities.items()
    }
    market_spread = pd.to_numeric(
        fold.test["feature_market_home_spread"], errors="coerce"
    )
    market_valid = market_spread.notna()
    if market_valid.any():
        favorite = np.where(
            market_spread.loc[market_valid] > 0,
            0.57,
            np.where(market_spread.loc[market_valid] < 0, 0.43, 0.5),
        )
        classifier_metrics["market_favorite"] = {
            "available": True,
            **classification_metrics(actual[market_valid.to_numpy()], favorite),
        }
    else:
        classifier_metrics["market_favorite"] = {
            "available": False,
            "count": 0,
            "reason": "No point-in-time spread is available in this window.",
        }
    regressor_metrics: dict[str, Any] = {}
    for name, feature, target_name in (
        (
            "current_picksports_home_margin",
            "feature_model_predicted_spread",
            "home_margin",
        ),
        (
            "current_picksports_total_points",
            "feature_model_predicted_total",
            "total_points",
        ),
    ):
        predictions = pd.to_numeric(fold.test[feature], errors="coerce")
        valid = predictions.notna()
        regressor_metrics[name] = (
            regression_metrics(
                fold.test.loc[
                    valid, schema.target_columns[target_name]
                ].to_numpy(dtype=float),
                predictions.loc[valid].to_numpy(dtype=float),
            )
            if valid.any()
            else {"available": False, "count": 0}
        )
    return {
        "classifiers": classifier_metrics,
        "regressors": regressor_metrics,
        "closing_market": {
            "available": False,
            "reason": "No point-in-time moneyline probability is declared in schema.",
        },
    }


def _calibration_residual_standard_deviations(
    models: ModelSet,
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
    total_model: dict[str, Any],
) -> dict[str, float]:
    transformed = transformed_features(models, calibration_frame, schema)
    margin_metrics = regression_metrics(
        calibration_frame[schema.target_columns["home_margin"]].to_numpy(dtype=float),
        models.margin_regressor.predict(transformed),
    )
    total_metrics = regression_metrics(
        calibration_frame[schema.target_columns["total_points"]].to_numpy(dtype=float),
        blended_total_predictions(
            calibration_frame,
            models.total_regressor.predict(transformed),
            schema,
            total_model,
        ),
    )
    return {
        "home_margin": max(1e-6, float(margin_metrics["residual_std"])),
        "total_points": max(1e-6, float(total_metrics["residual_std"])),
    }


def _deployment_refit_provenance(
    frame: pd.DataFrame,
    schema: FeatureSchema,
    season_profiles: list[dict[str, Any]],
    final_fold: ChronologicalFold,
    final_calibration: dict[str, dict[str, Any]],
    classifier_blend: dict[str, Any],
    champion: str,
    total_model: dict[str, Any],
) -> dict[str, Any]:
    seasons = sorted(int(value) for value in frame[schema.season_column].unique())
    rows_by_season = {
        str(season): int((frame[schema.season_column] == season).sum())
        for season in seasons
    }
    partial_seasons = [
        int(profile["season"])
        for profile in season_profiles
        if not bool(profile["complete"])
    ]
    cutoff = pd.Timestamp(frame[schema.time_column].max()).isoformat()
    feature_cutoff = pd.Timestamp(frame["features_available_at"].max()).isoformat()
    selected_calibrators = {
        model_name: final_calibration[model_name]["selected_method"]
        for model_name in CLASSIFIERS
    }
    return {
        "status": "completed",
        "strategy": "fit_all_eligible_settled_rows_after_frozen_evaluation",
        "training_cutoff": cutoff,
        "features_available_cutoff": feature_cutoff,
        "row_count": int(len(frame)),
        "seasons": seasons,
        "rows_by_season": rows_by_season,
        "partial_seasons_included": partial_seasons,
        "targets_required": list(schema.target_columns.values()),
        "models_refit": [
            "logistic_regression",
            "xgboost_classifier",
            "xgboost_home_margin",
            "xgboost_total_points_residual",
            "preprocessor",
        ],
        "selection_frozen_before_refit": {
            "champion_classifier": champion,
            "selected_calibrators": selected_calibrators,
            "classifier_blend": classifier_blend,
            "total_model": total_model,
            "source_calibration_season": final_fold.calibration_season,
            "source_held_out_test_season": final_fold.test_season,
        },
        "calibration_strategy": {
            "name": "reuse_pre_refit_chronological_calibrators",
            "selected_methods": selected_calibrators,
            "fit_row_count": int(len(final_fold.calibration)),
            "fit_season": final_fold.calibration_season,
            "statement": (
                "Saved calibrators were fit before deployment refit from "
                "chronologically out-of-sample calibration probabilities. "
                "They were not refit on in-sample predictions from the "
                "deployment models."
            ),
        },
        "pre_refit_metric_statement": (
            "All final-holdout and walk-forward metrics were computed before "
            "deployment refit. The refit artifact includes later eligible "
            "settled rows, including partial-season rows, and has no reported "
            "post-refit held-out metrics."
        ),
    }


def _select_total_model(
    models: ModelSet,
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
) -> dict[str, Any]:
    transformed = transformed_features(models, calibration_frame, schema)
    return select_total_residual_blend(
        calibration_frame,
        models.total_regressor.predict(transformed),
        schema,
        models.total_baseline_fallback,
    )


def _summarize_walk_forward(windows: list[dict[str, Any]]) -> dict[str, Any]:
    champions = [window["champion_classifier"] for window in windows]
    champion_briers = [
        window["classifiers"][window["champion_classifier"]]["test_calibrated"][
            "brier"
        ]
        for window in windows
    ]
    champion_log_losses = [
        window["classifiers"][window["champion_classifier"]]["test_calibrated"][
            "log_loss"
        ]
        for window in windows
    ]
    current_briers = [
        window["baselines"]["classifiers"]["current_picksports"]["brier"]
        for window in windows
    ]
    current_log_losses = [
        window["baselines"]["classifiers"]["current_picksports"]["log_loss"]
        for window in windows
    ]
    brier_deltas = [
        baseline - challenger
        for baseline, challenger in zip(current_briers, champion_briers, strict=True)
    ]
    log_loss_deltas = [
        baseline - challenger
        for baseline, challenger in zip(
            current_log_losses, champion_log_losses, strict=True
        )
    ]
    total_point_maes = [
        window["regressors"]["total_points"]["mae"] for window in windows
    ]
    baseline_total_point_maes = [
        window["baselines"]["regressors"][
            "current_picksports_total_points"
        ]["mae"]
        for window in windows
    ]
    return {
        "window_count": len(windows),
        "challenger_better_window_count": sum(delta > 0 for delta in brier_deltas),
        "avg_brier_delta": float(np.mean(brier_deltas)),
        "avg_log_loss_delta": float(np.mean(log_loss_deltas)),
        "test_seasons": [window["test_season"] for window in windows],
        "champion_counts": {
            model_name: champions.count(model_name)
            for model_name in CLASSIFIER_CANDIDATES
        },
        "average_champion_brier": float(np.mean(champion_briers)),
        "average_home_margin_mae": float(
            np.mean([window["regressors"]["home_margin"]["mae"] for window in windows])
        ),
        "average_total_points_mae": float(
            np.mean(total_point_maes)
        ),
        "average_picksports_total_points_mae": float(
            np.mean(baseline_total_point_maes)
        ),
        "avg_total_mae_delta": float(
            np.mean(
                [
                    baseline - challenger
                    for baseline, challenger in zip(
                        baseline_total_point_maes,
                        total_point_maes,
                        strict=True,
                    )
                ]
            )
        ),
        "total_residual_weights": [
            float(window["total_model"]["selected_residual_weight"])
            for window in windows
        ],
    }


def _promotion_summary(windows: list[dict[str, Any]]) -> dict[str, Any]:
    minimum_windows = 3
    required_win_rate = 0.60
    comparisons: dict[str, Any] = {}
    for baseline in ("elo_only", "current_picksports", "market_favorite"):
        baseline_windows = [
            window
            for window in windows
            if window["baselines"]["classifiers"][baseline].get("available", True)
        ]
        if not baseline_windows:
            comparisons[baseline] = {
                "available": False,
                "windows": 0,
                "wins": 0,
                "win_rate": None,
                "latest_window_win": None,
            }
            continue
        outcomes = [
            (
                window["classifiers"][window["champion_classifier"]][
                    "test_calibrated"
                ]["brier"]
                < window["baselines"]["classifiers"][baseline]["brier"]
            )
            for window in baseline_windows
        ]
        brier_deltas = [
            window["baselines"]["classifiers"][baseline]["brier"]
            - window["classifiers"][window["champion_classifier"]][
                "test_calibrated"
            ]["brier"]
            for window in baseline_windows
        ]
        log_loss_deltas = [
            window["baselines"]["classifiers"][baseline]["log_loss"]
            - window["classifiers"][window["champion_classifier"]][
                "test_calibrated"
            ]["log_loss"]
            for window in baseline_windows
        ]
        wins = sum(outcomes)
        comparisons[baseline] = {
            "available": True,
            "windows": len(outcomes),
            "wins": wins,
            "win_rate": float(wins / len(outcomes)) if outcomes else 0.0,
            "latest_window_win": bool(outcomes[-1]) if outcomes else False,
            "avg_brier_delta": float(np.mean(brier_deltas)),
            "avg_log_loss_delta": float(np.mean(log_loss_deltas)),
        }
    offline_gate = (
        len(windows) >= minimum_windows
        and all(
            item["win_rate"] >= required_win_rate and item["latest_window_win"]
            and item["avg_brier_delta"] > 0
            and item["avg_log_loss_delta"] > 0
            for item in comparisons.values()
            if item["available"]
        )
        and comparisons["current_picksports"]["available"]
        and comparisons["elo_only"]["available"]
    )
    return {
        "policy": {
            "minimum_chronological_windows": minimum_windows,
            "required_baseline_win_rate": required_win_rate,
            "requires_positive_average_brier_delta": True,
            "requires_positive_average_log_loss_delta": True,
            "requires_latest_window_win": True,
            "requires_live_shadow_evidence": True,
        },
        "baseline_comparisons": comparisons,
        "offline_challenger_gate_passed": offline_gate,
        "recommendation": (
            "eligible_for_live_shadow"
            if offline_gate
            else "retain_current_pipeline_and_continue_challenger_testing"
        ),
        "public_promotion_allowed": False,
        "public_promotion_reason": "Live shadow evidence is evaluated outside this package.",
    }


def _effective_tuning_config(
    schema: FeatureSchema,
    enabled: bool | None,
    trials: int | None,
    timeout_seconds: int | None,
) -> dict[str, Any]:
    config = dict(schema.training.get("tuning", {}))
    if enabled is not None:
        config["enabled"] = enabled
    if trials is not None:
        config["trials_per_model"] = trials
    if timeout_seconds is not None:
        config["timeout_seconds_per_model"] = timeout_seconds
    return config


def _effective_explanation_config(
    schema: FeatureSchema,
    enabled: bool | None,
    max_rows: int | None,
) -> dict[str, Any]:
    config = dict(schema.training.get("explanations", {}))
    if enabled is not None:
        config["enabled"] = enabled
    if max_rows is not None:
        config["max_rows"] = max_rows
    return config


def _set_deterministic_seed(seed: int) -> None:
    os.environ.setdefault("PYTHONHASHSEED", str(seed))
    np.random.seed(seed)


def _package_version() -> str:
    try:
        return importlib.metadata.version("picksports-nfl-ml")
    except importlib.metadata.PackageNotFoundError:
        return "0.2.0"


def _dependency_versions() -> dict[str, str]:
    packages = (
        "joblib",
        "numpy",
        "pandas",
        "pyarrow",
        "PyYAML",
        "scikit-learn",
        "optuna",
        "shap",
        "xgboost",
    )
    versions: dict[str, str] = {}
    for package in packages:
        try:
            versions[package] = importlib.metadata.version(package)
        except importlib.metadata.PackageNotFoundError:
            versions[package] = "unknown"
    return versions


def _code_version() -> str:
    configured = os.getenv("PICKSPORTS_CODE_VERSION")
    if configured:
        return configured
    repository = Path(__file__).resolve().parents[4]
    try:
        result = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=repository,
            check=True,
            capture_output=True,
            text=True,
        )
        version = result.stdout.strip()
        status = subprocess.run(
            ["git", "status", "--porcelain", "--", "ml/nfl"],
            cwd=repository,
            check=True,
            capture_output=True,
            text=True,
        )
        return f"{version}-dirty" if status.stdout.strip() else version
    except (OSError, subprocess.CalledProcessError):
        return "unknown"


def _source_hash() -> str:
    package_root = Path(__file__).resolve().parents[2]
    paths = [
        package_root / "Dockerfile",
        package_root / "pyproject.toml",
        package_root / "requirements.lock.txt",
        *sorted((package_root / "src").rglob("*.py")),
    ]
    digest = hashlib.sha256()
    for path in paths:
        digest.update(str(path.relative_to(package_root)).encode("utf-8"))
        digest.update(b"\0")
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
    return digest.hexdigest()
