from __future__ import annotations

from datetime import datetime, timezone
from typing import Any, Callable

import numpy as np
import optuna
import pandas as pd
from sklearn.metrics import brier_score_loss, mean_absolute_error
from xgboost import XGBClassifier, XGBRegressor

from picksports_nfl_ml.models import build_preprocessor
from picksports_nfl_ml.schema import FeatureSchema
from picksports_nfl_ml.totals import (
    blended_total_predictions,
    total_model_config,
    total_residual_targets,
)


MODEL_NAMES = (
    "xgboost_classifier",
    "xgboost_home_margin",
    "xgboost_total_points",
)


def tune_xgboost_models(
    train: pd.DataFrame,
    schema: FeatureSchema,
    seed: int,
    config: dict[str, Any],
) -> tuple[dict[str, dict[str, Any]], dict[str, Any]]:
    enabled = bool(config.get("enabled", False))
    provenance: dict[str, Any] = {
        "enabled": enabled,
        "strategy": "single_inner_season_chronological_validation",
        "test_season_access": False,
        "config": config,
        "generated_at": datetime.now(timezone.utc).isoformat(),
        "studies": {},
    }
    if not enabled:
        provenance["status"] = "disabled"
        return {}, provenance

    season_column = schema.season_column
    seasons = sorted(int(value) for value in train[season_column].unique())
    validation_count = int(config.get("validation_seasons", 1))
    if validation_count < 1 or len(seasons) <= validation_count:
        raise ValueError("Optuna tuning requires earlier train and validation seasons.")
    validation_seasons = seasons[-validation_count:]
    fit_seasons = seasons[:-validation_count]
    fit_frame = train[train[season_column].isin(fit_seasons)].copy()
    validation_frame = train[train[season_column].isin(validation_seasons)].copy()
    preprocessor = build_preprocessor()
    fit_features = preprocessor.fit_transform(fit_frame[schema.feature_names])
    validation_features = preprocessor.transform(
        validation_frame[schema.feature_names]
    )
    provenance["fit_seasons"] = fit_seasons
    provenance["validation_seasons"] = validation_seasons
    provenance["fit_rows"] = int(len(fit_frame))
    provenance["validation_rows"] = int(len(validation_frame))

    trials = int(config.get("trials_per_model", 12))
    timeout = config.get("timeout_seconds_per_model", 600)
    timeout_seconds = None if timeout is None else int(timeout)
    search_space = dict(config.get("search_space", {}))
    targets = schema.target_columns
    total_baseline_fallback = float(
        fit_frame[targets["total_points"]].astype(float).median()
    )
    objectives: dict[str, tuple[Callable[..., Any], np.ndarray, str]] = {
        "xgboost_classifier": (
            XGBClassifier,
            fit_frame[targets["home_win"]].to_numpy(dtype=int),
            "brier",
        ),
        "xgboost_home_margin": (
            XGBRegressor,
            fit_frame[targets["home_margin"]].to_numpy(dtype=float),
            "mae",
        ),
        "xgboost_total_points": (
            XGBRegressor,
            total_residual_targets(
                fit_frame,
                schema,
                total_baseline_fallback,
            ),
            "mae",
        ),
    }
    validation_targets = {
        "xgboost_classifier": validation_frame[
            targets["home_win"]
        ].to_numpy(dtype=int),
        "xgboost_home_margin": validation_frame[
            targets["home_margin"]
        ].to_numpy(dtype=float),
        "xgboost_total_points": validation_frame[
            targets["total_points"]
        ].to_numpy(dtype=float),
    }
    tuned: dict[str, dict[str, Any]] = {}
    optuna.logging.set_verbosity(optuna.logging.WARNING)
    for offset, model_name in enumerate(MODEL_NAMES):
        estimator_class, fit_target, metric_name = objectives[model_name]
        study = optuna.create_study(
            direction="minimize",
            sampler=optuna.samplers.TPESampler(seed=seed + offset),
            study_name=f"{model_name}-{seed}",
        )

        def objective(trial: optuna.Trial) -> float:
            parameters = _suggest_parameters(trial, search_space)
            common = {
                "tree_method": "hist",
                "random_state": seed + offset,
                "n_jobs": int(config.get("n_jobs", 1)),
                **parameters,
            }
            if model_name == "xgboost_classifier":
                estimator = estimator_class(
                    objective="binary:logistic",
                    eval_metric="logloss",
                    **common,
                )
                estimator.fit(fit_features, fit_target)
                predictions = estimator.predict_proba(validation_features)[:, 1]
                return float(
                    brier_score_loss(validation_targets[model_name], predictions)
                )
            estimator = estimator_class(
                objective="reg:squarederror",
                eval_metric="rmse",
                **common,
            )
            estimator.fit(fit_features, fit_target)
            predictions = estimator.predict(validation_features)
            if model_name == "xgboost_total_points":
                total_config = total_model_config(schema)
                predictions = blended_total_predictions(
                    validation_frame,
                    predictions,
                    schema,
                    {
                        "baseline_fallback": total_baseline_fallback,
                        "selected_residual_weight": total_config[
                            "max_residual_weight"
                        ],
                        "max_abs_adjustment": total_config[
                            "max_abs_adjustment"
                        ],
                    },
                )
            return float(
                mean_absolute_error(validation_targets[model_name], predictions)
            )

        study.optimize(
            objective,
            n_trials=trials,
            timeout=timeout_seconds,
            n_jobs=1,
            gc_after_trial=True,
            show_progress_bar=False,
        )
        tuned[model_name] = dict(study.best_params)
        provenance["studies"][model_name] = {
            "objective_metric": metric_name,
            "direction": "minimize",
            "best_value": float(study.best_value),
            "best_parameters": dict(study.best_params),
            "trial_count": len(study.trials),
            "trials": [
                {
                    "number": trial.number,
                    "state": trial.state.name,
                    "value": None if trial.value is None else float(trial.value),
                    "parameters": dict(trial.params),
                }
                for trial in study.trials
            ],
        }
    provenance["status"] = "completed"
    return tuned, provenance


def _suggest_parameters(
    trial: optuna.Trial,
    search_space: dict[str, Any],
) -> dict[str, Any]:
    defaults = {
        "n_estimators": [150, 450],
        "max_depth": [2, 5],
        "learning_rate": [0.02, 0.10],
        "subsample": [0.70, 1.0],
        "colsample_bytree": [0.70, 1.0],
        "min_child_weight": [1.0, 10.0],
        "reg_lambda": [0.5, 8.0],
        "reg_alpha": [0.0001, 1.0],
    }
    ranges = {**defaults, **search_space}
    return {
        "n_estimators": trial.suggest_int(
            "n_estimators", int(ranges["n_estimators"][0]), int(ranges["n_estimators"][1])
        ),
        "max_depth": trial.suggest_int(
            "max_depth", int(ranges["max_depth"][0]), int(ranges["max_depth"][1])
        ),
        "learning_rate": trial.suggest_float(
            "learning_rate",
            float(ranges["learning_rate"][0]),
            float(ranges["learning_rate"][1]),
            log=True,
        ),
        "subsample": trial.suggest_float(
            "subsample", float(ranges["subsample"][0]), float(ranges["subsample"][1])
        ),
        "colsample_bytree": trial.suggest_float(
            "colsample_bytree",
            float(ranges["colsample_bytree"][0]),
            float(ranges["colsample_bytree"][1]),
        ),
        "min_child_weight": trial.suggest_float(
            "min_child_weight",
            float(ranges["min_child_weight"][0]),
            float(ranges["min_child_weight"][1]),
            log=True,
        ),
        "reg_lambda": trial.suggest_float(
            "reg_lambda",
            float(ranges["reg_lambda"][0]),
            float(ranges["reg_lambda"][1]),
            log=True,
        ),
        "reg_alpha": trial.suggest_float(
            "reg_alpha",
            float(ranges["reg_alpha"][0]),
            float(ranges["reg_alpha"][1]),
            log=True,
        ),
    }
