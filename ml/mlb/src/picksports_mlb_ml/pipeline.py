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

from picksports_mlb_ml.artifacts import (
    refresh_artifact_descriptor,
    save_run_artifacts,
)
from picksports_mlb_ml.blending import (
    anchor_probabilities,
    select_probability_blend,
    weighted_probabilities,
)
from picksports_mlb_ml.calibration import (
    compare_calibrators,
    fit_calibrator,
)
from picksports_mlb_ml.data import load_immutable_dataset
from picksports_mlb_ml.hashing import sha256_json
from picksports_mlb_ml.inference import InferenceBundle
from picksports_mlb_ml.metrics import classification_metrics, regression_metrics
from picksports_mlb_ml.models import (
    ModelSet,
    classifier_probabilities,
    fit_model_set,
    transformed_features,
)
from picksports_mlb_ml.schema import FeatureSchema
from picksports_mlb_ml.splits import (
    ChronologicalFold,
    calibration_selection_split,
    chronological_holdout_fold,
    rolling_weekly_folds,
)


CLASSIFIERS = ("logistic_regression", "xgboost")
CALIBRATORS = ("platt", "isotonic")


def train(
    input_path: str | Path,
    schema_path: str | Path,
    output_dir: str | Path,
    expected_dataset_sha256: str | None = None,
    run_id: str | None = None,
    seed: int | None = None,
) -> dict[str, Any]:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(
        input_path, schema, expected_dataset_sha256
    )
    config = schema.training
    effective_seed = int(seed if seed is not None else config["seed"])
    np.random.seed(effective_seed)

    split_config = dict(config["splits"])
    final_fold = chronological_holdout_fold(
        frame,
        schema,
        calibration_weeks=int(split_config["final_calibration_weeks"]),
        test_weeks=int(split_config["final_test_weeks"]),
        minimum_training_rows=int(split_config["minimum_training_rows"]),
    )
    rolling_folds = rolling_weekly_folds(
        frame,
        schema,
        initial_training_weeks=int(split_config["rolling_initial_training_weeks"]),
        calibration_weeks=int(split_config["rolling_calibration_weeks"]),
        test_weeks=int(split_config.get("rolling_test_weeks", 1)),
        rolling_training_weeks=_optional_int(
            split_config.get("rolling_training_weeks")
        ),
        maximum_windows=_optional_int(split_config.get("maximum_rolling_windows")),
        minimum_training_rows=int(split_config["minimum_training_rows"]),
    )
    rolling_results = [
        _evaluate_fold(fold, schema, effective_seed) for fold in rolling_folds
    ]

    evaluation_models = fit_model_set(final_fold.train, schema, effective_seed)
    evaluation_calibration = _calibrate_models(
        evaluation_models,
        final_fold.calibration,
        schema,
        effective_seed,
    )
    champion, blend = _select_champion(
        evaluation_calibration, final_fold.calibration, schema
    )
    final_metrics = _evaluate_saved_model(
        evaluation_models,
        evaluation_calibration,
        final_fold,
        schema,
        blend,
    )
    residuals = _residual_standard_deviations(
        evaluation_models, final_fold.calibration, schema
    )
    deployment_models, deployment_calibrators, deployment_refit = (
        _fit_deployment_refit(
            frame=frame,
            evaluation_models=evaluation_models,
            final_fold=final_fold,
            schema=schema,
            seed=effective_seed,
            selected_calibrators={
                model: evaluation_calibration[model]["selected_method"]
                for model in CLASSIFIERS
            },
            champion=champion,
            blend=blend,
        )
    )

    model_run_id = run_id or str(uuid.uuid4())
    artifact_id = str(uuid.uuid4())
    source_hash = _source_hash()
    config_hash = sha256_json(
        {
            "schema": schema.raw,
            "seed": effective_seed,
            "package_version": _package_version(),
            "source_hash": source_hash,
        }
    )
    generated_at = datetime.now(timezone.utc).isoformat()
    boundaries = _fold_boundaries(final_fold)
    manifest: dict[str, Any] = {
        "manifest_version": 1,
        "model_type": "mlb_tabular_bundle",
        "model_version": "mlb-tabular-v1",
        "package": "picksports_mlb_ml",
        "module": "picksports_mlb_ml",
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
        "classifier_blend": blend,
        "selected_calibrators": {
            model: evaluation_calibration[model]["selected_method"]
            for model in CLASSIFIERS
        },
        "residual_standard_deviation": residuals,
        "chronological_boundaries": boundaries,
        "deployment_refit": deployment_refit,
        "training_seasons": sorted(
            int(value) for value in frame[schema.season_column].unique()
        ),
        "dependencies": _dependency_versions(),
    }
    evaluation = {
        "report_type": "mlb_tabular_walk_forward_evaluation",
        "model_type": "mlb_tabular_bundle",
        "package": "picksports_mlb_ml",
        "generated_at": generated_at,
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "dataset": {
            "path": str(Path(input_path).expanduser().resolve()),
            "sha256": dataset_hash,
            "rows": int(len(frame)),
            "seasons": sorted(
                int(value) for value in frame[schema.season_column].unique()
            ),
            "start_at": frame[schema.time_column].min().isoformat(),
            "end_at": frame[schema.time_column].max().isoformat(),
        },
        "feature_schema": {
            "version": schema.version,
            "sha256": schema.hash,
            "features": schema.feature_names,
        },
        "selection_policy": {
            "split": (
                "All train, calibration, and test boundaries follow observed MLB "
                "game weeks. No row later than a boundary may enter an earlier split."
            ),
            "calibration": (
                "Platt and isotonic calibrators fit on the first chronological "
                "calibration segment and are selected on the later segment. The "
                "test window never affects calibration or classifier selection."
            ),
            "blend": (
                "The optional blend is selected on calibration data only. Missing "
                "Picksports or market anchors are ignored per row and remaining "
                "model weights are renormalized."
            ),
        },
        "final_holdout": {
            **boundaries,
            "champion_classifier": champion,
            "classifier_blend": blend,
            "metrics_provenance": "pre_refit_untouched_chronological_holdout",
            **final_metrics,
        },
        "rolling_weekly": {
            "windows": rolling_results,
            "summary": _summarize_rolling(rolling_results),
        },
        "deployment_refit": deployment_refit,
        "promotion_summary": _promotion_summary(rolling_results),
    }

    run_dir = save_run_artifacts(
        output_root=output_dir,
        manifest=manifest,
        evaluation=evaluation,
        models=deployment_models,
        calibrators=deployment_calibrators,
        schema=schema,
    )
    example = InferenceBundle.load(run_dir).predict(final_fold.test.iloc[[0]])[0]
    with (run_dir / "prediction_example.json").open("w", encoding="utf-8") as handle:
        json.dump(example, handle, indent=2, sort_keys=True)
        handle.write("\n")
    refresh_artifact_descriptor(run_dir, "prediction_example.json")

    return {
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "model_type": "mlb_tabular_bundle",
        "run_dir": str(run_dir),
        "dataset_hash": dataset_hash,
        "config_hash": config_hash,
        "champion_classifier": champion,
        "test_start_at": final_fold.test_start_at,
        "test_end_at": final_fold.test_end_at,
        "evaluation_path": str(run_dir / "evaluation.json"),
        "manifest_path": str(run_dir / "manifest.json"),
        "prediction_example_path": str(run_dir / "prediction_example.json"),
    }


def evaluate_rolling(
    input_path: str | Path,
    schema_path: str | Path,
    output_path: str | Path | None = None,
    expected_dataset_sha256: str | None = None,
    seed: int | None = None,
) -> dict[str, Any]:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(
        input_path, schema, expected_dataset_sha256
    )
    config = schema.training
    split = dict(config["splits"])
    effective_seed = int(seed if seed is not None else config["seed"])
    folds = rolling_weekly_folds(
        frame,
        schema,
        initial_training_weeks=int(split["rolling_initial_training_weeks"]),
        calibration_weeks=int(split["rolling_calibration_weeks"]),
        test_weeks=int(split.get("rolling_test_weeks", 1)),
        rolling_training_weeks=_optional_int(split.get("rolling_training_weeks")),
        maximum_windows=_optional_int(split.get("maximum_rolling_windows")),
        minimum_training_rows=int(split["minimum_training_rows"]),
    )
    windows = [_evaluate_fold(fold, schema, effective_seed) for fold in folds]
    report = {
        "report_type": "mlb_tabular_walk_forward_evaluation",
        "model_type": "mlb_tabular_bundle",
        "package": "picksports_mlb_ml",
        "dataset_hash": dataset_hash,
        "feature_schema_hash": schema.hash,
        "windows": windows,
        "summary": _summarize_rolling(windows),
        "promotion_summary": _promotion_summary(windows),
    }
    if output_path:
        resolved = Path(output_path).expanduser().resolve()
        resolved.parent.mkdir(parents=True, exist_ok=True)
        resolved.write_text(
            json.dumps(report, indent=2, sort_keys=True) + "\n",
            encoding="utf-8",
        )
    return report


def _evaluate_fold(
    fold: ChronologicalFold,
    schema: FeatureSchema,
    seed: int,
) -> dict[str, Any]:
    models = fit_model_set(fold.train, schema, seed)
    calibration = _calibrate_models(models, fold.calibration, schema, seed)
    champion, blend = _select_champion(calibration, fold.calibration, schema)
    return {
        **_fold_boundaries(fold),
        "champion_classifier": champion,
        "classifier_blend": blend,
        **_evaluate_saved_model(models, calibration, fold, schema, blend),
    }


def _fit_deployment_refit(
    frame: pd.DataFrame,
    evaluation_models: ModelSet,
    final_fold: ChronologicalFold,
    schema: FeatureSchema,
    seed: int,
    selected_calibrators: dict[str, str],
    champion: str,
    blend: dict[str, Any],
) -> tuple[
    ModelSet,
    dict[str, dict[str, Any]],
    dict[str, Any],
]:
    calibration_frame = pd.concat(
        [final_fold.calibration, final_fold.test],
        ignore_index=True,
    ).sort_values([schema.time_column, schema.id_column], kind="stable")
    calibration_targets = calibration_frame[
        schema.target_columns["home_win"]
    ].to_numpy(dtype=int)
    transformed = transformed_features(
        evaluation_models,
        calibration_frame,
        schema,
    )
    deployment_calibrators = {
        model: {
            method: fit_calibrator(
                method,
                classifier_probabilities(
                    evaluation_models,
                    model,
                    transformed,
                ),
                calibration_targets,
                seed,
            )
            for method in CALIBRATORS
        }
        for model in CLASSIFIERS
    }
    deployment_models = fit_model_set(frame, schema, seed)
    observed_weeks = _observed_week_labels(frame, schema)
    cutoff_at = frame[schema.time_column].max().isoformat()
    provenance = {
        "performed": True,
        "training_cutoff_at": cutoff_at,
        "eligible_row_count": int(len(frame)),
        "eligible_row_start_at": frame[schema.time_column].min().isoformat(),
        "eligible_row_end_at": cutoff_at,
        "seasons": sorted(
            int(value) for value in frame[schema.season_column].unique()
        ),
        "observed_weeks": {
            "count": len(observed_weeks),
            "values": observed_weeks,
        },
        "base_estimator_strategy": (
            "After all evaluation metrics and model choices were frozen, the "
            "saved preprocessor, classifiers, and regressors were fit on every "
            "eligible settled row through the training cutoff."
        ),
        "calibration_strategy": {
            "method_selection_source": (
                "Methods were selected on the chronological final calibration "
                "selection segment before the final holdout was evaluated."
            ),
            "fit_prediction_source": (
                "Saved calibrators were refit after evaluation using out-of-sample "
                "probabilities from the pre-refit estimators over the final "
                "calibration and test periods."
            ),
            "fit_row_count": int(len(calibration_frame)),
            "fit_start_at": calibration_frame[schema.time_column].min().isoformat(),
            "fit_end_at": calibration_frame[schema.time_column].max().isoformat(),
            "selected_methods": selected_calibrators,
        },
        "selection_strategy": {
            "champion_classifier": champion,
            "blend_weights_frozen_pre_refit": dict(blend["weights"]),
            "selection_repeated_after_refit": False,
        },
        "held_out_metrics_are_pre_refit": True,
        "held_out_metrics_provenance": (
            "All final-holdout and rolling-window metrics are pre-refit metrics "
            "computed from untouched chronological holdouts. They do not describe "
            "in-sample performance of the saved refit estimators."
        ),
        "artifact_estimators": "deployment_refit",
    }
    return deployment_models, deployment_calibrators, provenance


def _calibrate_models(
    models: ModelSet,
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
    seed: int,
) -> dict[str, dict[str, Any]]:
    fit_frame, selection_frame = calibration_selection_split(
        calibration_frame,
        schema,
        float(schema.training["calibration_fit_fraction"]),
    )
    target = schema.target_columns["home_win"]
    transformed = {
        "fit": transformed_features(models, fit_frame, schema),
        "selection": transformed_features(models, selection_frame, schema),
        "full": transformed_features(models, calibration_frame, schema),
    }
    result: dict[str, dict[str, Any]] = {}
    for model in CLASSIFIERS:
        fit_probabilities = classifier_probabilities(models, model, transformed["fit"])
        selection_probabilities = classifier_probabilities(
            models, model, transformed["selection"]
        )
        method, comparison = compare_calibrators(
            fit_probabilities,
            fit_frame[target].to_numpy(dtype=int),
            selection_probabilities,
            selection_frame[target].to_numpy(dtype=int),
            seed,
        )
        full_probabilities = classifier_probabilities(models, model, transformed["full"])
        calibrators = {
            candidate: fit_calibrator(
                candidate,
                full_probabilities,
                calibration_frame[target].to_numpy(dtype=int),
                seed,
            )
            for candidate in CALIBRATORS
        }
        selection_calibrator = fit_calibrator(
            method,
            fit_probabilities,
            fit_frame[target].to_numpy(dtype=int),
            seed,
        )
        result[model] = {
            "selected_method": method,
            "selection_metrics": comparison,
            "selection_probabilities": selection_calibrator.predict(
                selection_probabilities
            ),
            "calibrators": calibrators,
        }
    return result


def _select_champion(
    calibration: dict[str, dict[str, Any]],
    calibration_frame: pd.DataFrame,
    schema: FeatureSchema,
) -> tuple[str, dict[str, Any]]:
    _, selection = calibration_selection_split(
        calibration_frame,
        schema,
        float(schema.training["calibration_fit_fraction"]),
    )
    blend_config = dict(schema.training.get("blend", {}))
    anchors, coverage = anchor_probabilities(selection, schema.anchor_columns)
    blend = select_probability_blend(
        {model: calibration[model]["selection_probabilities"] for model in CLASSIFIERS},
        anchors,
        selection[schema.target_columns["home_win"]].to_numpy(dtype=int),
        enabled=bool(blend_config.get("enabled", True)),
        minimum_anchor_coverage=float(
            blend_config.get("minimum_anchor_coverage", 0.5)
        ),
        maximum_anchor_weight=float(blend_config.get("maximum_anchor_weight", 0.7)),
    )
    blend["anchor_columns"] = schema.anchor_columns
    blend["anchor_coverage"] = coverage
    candidates = {
        model: calibration[model]["selection_metrics"][
            calibration[model]["selected_method"]
        ]
        for model in CLASSIFIERS
    }
    candidates["blend"] = blend["selection_metrics"]
    champion = min(
        candidates,
        key=lambda model: (
            candidates[model]["brier"],
            candidates[model]["log_loss"],
            model,
        ),
    )
    return champion, blend


def _evaluate_saved_model(
    models: ModelSet,
    calibration: dict[str, dict[str, Any]],
    fold: ChronologicalFold,
    schema: FeatureSchema,
    blend: dict[str, Any],
) -> dict[str, Any]:
    transformed = transformed_features(models, fold.test, schema)
    targets = fold.test[schema.target_columns["home_win"]].to_numpy(dtype=int)
    probabilities: dict[str, np.ndarray] = {}
    classifiers: dict[str, Any] = {}
    for model in CLASSIFIERS:
        raw = classifier_probabilities(models, model, transformed)
        method = calibration[model]["selected_method"]
        calibrated = calibration[model]["calibrators"][method].predict(raw)
        probabilities[model] = calibrated
        classifiers[model] = {
            "selected_calibrator": method,
            "calibration_selection": calibration[model]["selection_metrics"],
            "test_uncalibrated": classification_metrics(targets, raw),
            "test_calibrated": classification_metrics(targets, calibrated),
        }
    anchors, anchor_coverage = anchor_probabilities(
        fold.test, blend["anchor_columns"]
    )
    blended = np.clip(
        weighted_probabilities(probabilities, anchors, blend["weights"]),
        blend["probability_floor"],
        1 - blend["probability_floor"],
    )
    blended_metrics = classification_metrics(targets, blended)
    classifiers["blend"] = {
        "selected_calibrator": "weighted_blend",
        "weights": blend["weights"],
        "anchor_coverage": anchor_coverage,
        "calibration_selection": blend["selection_metrics"],
        "test_uncalibrated": blended_metrics,
        "test_calibrated": blended_metrics,
    }
    return {
        "classifiers": classifiers,
        "regressors": {
            "home_margin": regression_metrics(
                fold.test[schema.target_columns["home_margin"]].to_numpy(float),
                models.margin_regressor.predict(transformed),
            ),
            "total_points": regression_metrics(
                fold.test[schema.target_columns["total_points"]].to_numpy(float),
                models.total_regressor.predict(transformed),
            ),
        },
        "baselines": _evaluate_baselines(fold, schema),
    }


def _evaluate_baselines(
    fold: ChronologicalFold,
    schema: FeatureSchema,
) -> dict[str, Any]:
    target_column = schema.target_columns["home_win"]
    targets = fold.test[target_column].to_numpy(dtype=int)
    home_rate = float(fold.train[target_column].mean())
    classifiers: dict[str, Any] = {
        "home_rate": {
            "available": True,
            **classification_metrics(
                targets, np.full(len(targets), home_rate, dtype=float)
            ),
        }
    }
    for name, column in (
        ("current_picksports", "feature_model_win_probability"),
        ("market_implied", "feature_market_home_win_probability"),
    ):
        values = pd.to_numeric(fold.test.get(column), errors="coerce")
        valid = values.notna() if isinstance(values, pd.Series) else pd.Series(False, index=fold.test.index)
        classifiers[name] = (
            {
                "available": True,
                "coverage": float(valid.mean()),
                **classification_metrics(
                    targets[valid.to_numpy()], values.loc[valid].to_numpy(float)
                ),
            }
            if valid.any()
            else {"available": False, "count": 0, "coverage": 0.0}
        )
    regressors: dict[str, Any] = {}
    for name, feature, target in (
        (
            "current_picksports_home_margin",
            "feature_model_predicted_margin",
            "home_margin",
        ),
        (
            "current_picksports_total_points",
            "feature_model_predicted_total",
            "total_points",
        ),
    ):
        values = pd.to_numeric(fold.test.get(feature), errors="coerce")
        valid = values.notna() if isinstance(values, pd.Series) else pd.Series(False, index=fold.test.index)
        regressors[name] = (
            {
                "available": True,
                **regression_metrics(
                    fold.test.loc[valid, schema.target_columns[target]].to_numpy(float),
                    values.loc[valid].to_numpy(float),
                ),
            }
            if valid.any()
            else {"available": False, "count": 0}
        )
    return {"classifiers": classifiers, "regressors": regressors}


def _residual_standard_deviations(
    models: ModelSet,
    frame: pd.DataFrame,
    schema: FeatureSchema,
) -> dict[str, float]:
    transformed = transformed_features(models, frame, schema)
    margin = regression_metrics(
        frame[schema.target_columns["home_margin"]].to_numpy(float),
        models.margin_regressor.predict(transformed),
    )
    total = regression_metrics(
        frame[schema.target_columns["total_points"]].to_numpy(float),
        models.total_regressor.predict(transformed),
    )
    return {
        "home_margin": max(1e-6, float(margin["residual_std"])),
        "total_points": max(1e-6, float(total["residual_std"])),
    }


def _summarize_rolling(windows: list[dict[str, Any]]) -> dict[str, Any]:
    champion_metrics = [
        window["classifiers"][window["champion_classifier"]]["test_calibrated"]
        for window in windows
    ]
    comparisons = []
    for window, challenger in zip(windows, champion_metrics, strict=True):
        baseline = window["baselines"]["classifiers"]["current_picksports"]
        if baseline["available"] and baseline["count"] == challenger["count"]:
            comparisons.append(
                {
                    "brier_delta": baseline["brier"] - challenger["brier"],
                    "log_loss_delta": baseline["log_loss"] - challenger["log_loss"],
                }
            )
    return {
        "window_count": len(windows),
        "test_windows": [
            {"start_at": window["test_start_at"], "end_at": window["test_end_at"]}
            for window in windows
        ],
        "average_champion_brier": float(
            np.mean([metrics["brier"] for metrics in champion_metrics])
        ),
        "average_champion_log_loss": float(
            np.mean([metrics["log_loss"] for metrics in champion_metrics])
        ),
        "average_home_margin_mae": float(
            np.mean([window["regressors"]["home_margin"]["mae"] for window in windows])
        ),
        "average_total_points_mae": float(
            np.mean([window["regressors"]["total_points"]["mae"] for window in windows])
        ),
        "picksports_comparison_windows": len(comparisons),
        "average_brier_delta_vs_picksports": (
            float(np.mean([item["brier_delta"] for item in comparisons]))
            if comparisons
            else None
        ),
        "average_log_loss_delta_vs_picksports": (
            float(np.mean([item["log_loss_delta"] for item in comparisons]))
            if comparisons
            else None
        ),
    }


def _promotion_summary(windows: list[dict[str, Any]]) -> dict[str, Any]:
    summary = _summarize_rolling(windows)
    minimum_windows = 4
    offline_gate = (
        summary["window_count"] >= minimum_windows
        and summary["picksports_comparison_windows"] >= minimum_windows
        and (summary["average_brier_delta_vs_picksports"] or 0) > 0
        and (summary["average_log_loss_delta_vs_picksports"] or 0) > 0
    )
    return {
        "minimum_weekly_windows": minimum_windows,
        "offline_challenger_gate_passed": offline_gate,
        "requires_live_shadow_evidence": True,
        "public_promotion_allowed": False,
        "recommendation": (
            "eligible_for_live_shadow"
            if offline_gate
            else "retain_current_pipeline_and_continue_weekly_evaluation"
        ),
    }


def _fold_boundaries(fold: ChronologicalFold) -> dict[str, str]:
    return {
        "train_start_at": fold.train_start_at,
        "train_end_at": fold.train_end_at,
        "calibration_start_at": fold.calibration_start_at,
        "calibration_end_at": fold.calibration_end_at,
        "test_start_at": fold.test_start_at,
        "test_end_at": fold.test_end_at,
    }


def _observed_week_labels(
    frame: pd.DataFrame,
    schema: FeatureSchema,
) -> list[str]:
    timestamps = (
        frame[schema.time_column]
        .dt.tz_convert("UTC")
        .dt.tz_localize(None)
        .dt.to_period("W-SUN")
        .dt.start_time
    )
    return [
        pd.Timestamp(value).date().isoformat()
        for value in sorted(timestamps.unique())
    ]


def _optional_int(value: Any) -> int | None:
    return None if value is None else int(value)


def _package_version() -> str:
    try:
        return importlib.metadata.version("picksports-mlb-ml")
    except importlib.metadata.PackageNotFoundError:
        return "0.2.0"


def _dependency_versions() -> dict[str, str]:
    versions: dict[str, str] = {}
    for package in (
        "joblib",
        "numpy",
        "pandas",
        "pyarrow",
        "PyYAML",
        "scikit-learn",
        "xgboost",
    ):
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
        revision = subprocess.run(
            ["git", "rev-parse", "HEAD"],
            cwd=repository,
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()
        dirty = subprocess.run(
            ["git", "status", "--porcelain", "--", "ml/mlb"],
            cwd=repository,
            check=True,
            capture_output=True,
            text=True,
        ).stdout.strip()
        return f"{revision}-dirty" if dirty else revision
    except (OSError, subprocess.CalledProcessError):
        return "unknown"


def _source_hash() -> str:
    package_root = Path(__file__).resolve().parents[2]
    paths = [
        package_root / "Dockerfile",
        package_root / "pyproject.toml",
        package_root / "requirements.lock.txt",
        *sorted((package_root / "config").rglob("*.yaml")),
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
