from __future__ import annotations

import hashlib
import importlib.metadata
import json
import math
import uuid
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import joblib
import numpy as np
import pandas as pd
import yaml
from sklearn.impute import SimpleImputer
from sklearn.linear_model import LogisticRegression
from sklearn.metrics import accuracy_score, log_loss
from sklearn.pipeline import Pipeline
from sklearn.preprocessing import StandardScaler
from xgboost import XGBClassifier

from picksports_mlb_ml.calibration import ProbabilityCalibrator, fit_calibrator
from picksports_mlb_ml.hashing import sha256_file, sha256_json


CLASSES = np.asarray([0, 1, 2], dtype=int)
MARKETS = ("first_3_moneyline", "first_5_moneyline")


@dataclass
class MulticlassCalibrator:
    method: str
    calibrators: dict[int, ProbabilityCalibrator]
    probability_floor: float

    def predict(self, probabilities: np.ndarray) -> np.ndarray:
        raw = np.asarray(probabilities, dtype=float)
        calibrated = np.column_stack(
            [
                self.calibrators[label].predict(raw[:, label])
                for label in CLASSES
            ]
        )
        calibrated = np.clip(
            calibrated, self.probability_floor, 1 - self.probability_floor
        )
        return calibrated / calibrated.sum(axis=1, keepdims=True)


def train_period_models(
    input_path: str | Path,
    schema_path: str | Path,
    output_path: str | Path,
    evaluation_path: str | Path,
    manifest_path: str | Path,
    seed: int | None = None,
) -> dict[str, Any]:
    dataset_path = Path(input_path).expanduser().resolve()
    schema_file = Path(schema_path).expanduser().resolve()
    config = yaml.safe_load(schema_file.read_text(encoding="utf-8"))
    frame = pd.read_csv(dataset_path)
    features = list(config["features"])
    _validate_period_frame(frame, features)
    training = config["training"]
    resolved_seed = int(seed if seed is not None else training["seed"])
    dataset_hash = sha256_file(dataset_path)
    schema_hash = sha256_file(schema_file)
    evaluation: dict[str, Any] = {"markets": {}, "dataset_rows": len(frame)}
    bundles: dict[str, Any] = {}

    for market in MARKETS:
        market_frame = (
            frame.loc[frame["market_type"] == market]
            .sort_values(["game_start_at", "game_id"])
            .reset_index(drop=True)
        )
        if market_frame.empty:
            raise ValueError(f"Dataset has no rows for {market}.")
        rolling = _rolling_evaluation(
            market_frame, features, training, resolved_seed
        )
        deployment = _fit_deployment_models(
            market_frame,
            features,
            training,
            resolved_seed,
            preferred_model=rolling["selected_model"],
        )
        evaluation["markets"][market] = {
            "rows": len(market_frame),
            "seasons": sorted(int(value) for value in market_frame["season"].unique()),
            "rolling": rolling,
            "selected_model": deployment["selected_model"],
            "selected_calibration": deployment["selected_calibration"],
            "probability_buckets": deployment["probability_buckets"],
            "quote_evaluation": _quote_evaluation(
                market_frame,
                deployment["holdout_probabilities"],
                deployment["holdout_indices"],
            ),
        }
        bundles[market] = {
            key: value
            for key, value in deployment.items()
            if key
            not in {
                "holdout_probabilities",
                "holdout_indices",
                "probability_buckets",
            }
        }

    promotion = _promotion_summary(evaluation["markets"], training["promotion"])
    model_run_id = str(uuid.uuid4())
    artifact_id = str(uuid.uuid4())
    manifest = {
        "model_run_id": model_run_id,
        "artifact_id": artifact_id,
        "model_version": "mlb-period-multiclass-v1",
        "feature_schema_version": config["schema_version"],
        "feature_schema_hash": schema_hash,
        "dataset_hash": dataset_hash,
        "config_hash": sha256_json(config),
        "seed": resolved_seed,
        "markets": list(MARKETS),
        "training_seasons": sorted(int(value) for value in frame["season"].unique()),
        "dataset_rows": len(frame),
        "dataset_start_at": frame["game_start_at"].min().isoformat(),
        "dataset_end_at": frame["game_start_at"].max().isoformat(),
        "package_version": _package_version(),
        "dependencies": _dependency_versions(),
        "source_hash": _source_hash(),
        "promotion_summary": promotion,
    }
    bundle = {
        "manifest": manifest,
        "features": features,
        "markets": bundles,
    }
    output = Path(output_path).expanduser().resolve()
    output.parent.mkdir(parents=True, exist_ok=True)
    joblib.dump(bundle, output)
    manifest["artifact_hash"] = sha256_file(output)
    evaluation["promotion_summary"] = promotion
    evaluation["manifest"] = manifest
    _write_json(Path(evaluation_path), evaluation)
    _write_json(Path(manifest_path), manifest)
    return {
        "bundle_path": str(output),
        "evaluation_path": str(Path(evaluation_path).resolve()),
        "manifest_path": str(Path(manifest_path).resolve()),
        **manifest,
    }


def predict_period_models(
    bundle_path: str | Path, input_path: str | Path
) -> list[dict[str, Any]]:
    bundle = joblib.load(Path(bundle_path).expanduser().resolve())
    manifest = bundle["manifest"]
    frame = _read_frame(input_path)
    rows: list[dict[str, Any]] = []

    for _, row in frame.iterrows():
        market = str(row["market_type"])
        if market not in bundle["markets"]:
            raise ValueError(f"Bundle does not support {market}.")
        market_bundle = bundle["markets"][market]
        features = bundle["features"]
        values = pd.DataFrame([{name: row.get(name, np.nan) for name in features}])
        transformed = market_bundle["preprocessor"].transform(values[features])
        model_name = market_bundle["selected_model"]
        model = market_bundle["models"][model_name]
        raw = _ordered_probabilities(model, transformed)
        calibration = market_bundle["selected_calibration"][model_name]
        probabilities = market_bundle["calibrators"][model_name][calibration].predict(
            raw
        )[0]
        away, tie, home = (float(value) for value in probabilities)
        decided = max(1e-9, home + away)
        conditional_home = home / decided
        conditional_away = away / decided
        feature_payload = {
            name: None if pd.isna(values.iloc[0][name]) else float(values.iloc[0][name])
            for name in features
        }
        rows.append(
            {
                "market_type": market,
                "home_win_probability": round(home, 6),
                "away_win_probability": round(away, 6),
                "tie_probability": round(tie, 6),
                "conditional_home_win_probability": round(conditional_home, 6),
                "conditional_away_win_probability": round(conditional_away, 6),
                "fair_home_price": _american_price(conditional_home),
                "fair_away_price": _american_price(conditional_away),
                "uncertainty": round(_entropy(probabilities), 6),
                "model_name": model_name,
                "calibration_method": calibration,
                "model_run_id": manifest["model_run_id"],
                "artifact_id": manifest["artifact_id"],
                "dataset_hash": manifest["dataset_hash"],
                "feature_hash": sha256_json(feature_payload),
            }
        )
    return rows


def _fit_deployment_models(
    frame: pd.DataFrame,
    features: list[str],
    config: dict[str, Any],
    seed: int,
    preferred_model: str | None = None,
) -> dict[str, Any]:
    calibration_size = max(3, int(len(frame) * config["calibration_fraction"]))
    core_end = max(1, len(frame) - calibration_size)
    calibration_end = core_end + max(1, int(calibration_size * 0.4))
    selection_end = calibration_end + max(1, int(calibration_size * 0.3))
    core = frame.iloc[:core_end]
    calibration_fit = frame.iloc[core_end:calibration_end]
    selection = frame.iloc[calibration_end:selection_end]
    holdout = frame.iloc[selection_end:]
    if selection.empty or holdout.empty:
        raise ValueError(
            "Period deployment requires separate calibration, selection, and holdout rows."
        )
    preprocessor = _preprocessor()
    core_values = preprocessor.fit_transform(core[features])
    calibration_values = preprocessor.transform(calibration_fit[features])
    selection_values = preprocessor.transform(selection[features])
    holdout_values = preprocessor.transform(holdout[features])
    models = _fit_models(core_values, core["target_class"].to_numpy(), config, seed)
    calibrators: dict[str, dict[str, MulticlassCalibrator]] = {}
    comparison: dict[str, dict[str, dict[str, Any]]] = {}
    selected_calibration: dict[str, str] = {}
    holdout_probabilities: dict[str, np.ndarray] = {}

    for name, model in models.items():
        raw = _ordered_probabilities(model, calibration_values)
        selection_raw = _ordered_probabilities(model, selection_values)
        calibrators[name] = {
            method: _fit_multiclass_calibrator(
                method,
                raw,
                calibration_fit["target_class"].to_numpy(),
                seed,
                float(config["probability_floor"]),
            )
            for method in ("platt", "isotonic")
        }
        comparison[name] = {
            "uncalibrated": _multiclass_metrics(
                selection["target_class"].to_numpy(), selection_raw
            )
        }
        for method, calibrator in calibrators[name].items():
            comparison[name][method] = _multiclass_metrics(
                selection["target_class"].to_numpy(),
                calibrator.predict(selection_raw),
            )
        selected_calibration[name] = min(
            ("platt", "isotonic"),
            key=lambda method: (
                comparison[name][method]["log_loss"],
                comparison[name][method]["brier"],
            ),
        )
        holdout_probabilities[name] = calibrators[name][
            selected_calibration[name]
        ].predict(_ordered_probabilities(model, holdout_values))

    selected_model = (
        preferred_model
        if preferred_model in models
        else min(
            models,
            key=lambda name: (
                comparison[name][selected_calibration[name]]["log_loss"],
                comparison[name][selected_calibration[name]]["brier"],
            ),
        )
    )
    return {
        "preprocessor": preprocessor,
        "models": models,
        "calibrators": calibrators,
        "selected_model": selected_model,
        "selected_calibration": selected_calibration,
        "calibration_comparison": comparison,
        "holdout_probabilities": holdout_probabilities[selected_model],
        "holdout_indices": holdout.index.to_numpy(),
        "probability_buckets": _probability_buckets(
            holdout["target_class"].to_numpy(),
            holdout_probabilities[selected_model],
        ),
    }


def _rolling_evaluation(
    frame: pd.DataFrame,
    features: list[str],
    config: dict[str, Any],
    seed: int,
) -> dict[str, Any]:
    seasons = sorted(int(value) for value in frame["season"].unique())
    windows: list[dict[str, Any]] = []
    for test_season in seasons[2:]:
        prior = [season for season in seasons if season < test_season]
        calibration_season = max(prior)
        core = frame.loc[frame["season"].isin(prior[:-1])]
        calibration = frame.loc[frame["season"] == calibration_season]
        test = frame.loc[frame["season"] == test_season]
        if core.empty or calibration.empty or test.empty:
            continue
        calibration_split = max(1, min(len(calibration) - 1, int(len(calibration) * 0.6)))
        calibration_fit = calibration.iloc[:calibration_split]
        calibration_selection = calibration.iloc[calibration_split:]
        if calibration_selection.empty:
            continue
        preprocessor = _preprocessor()
        core_values = preprocessor.fit_transform(core[features])
        calibration_values = preprocessor.transform(calibration_fit[features])
        selection_values = preprocessor.transform(calibration_selection[features])
        test_values = preprocessor.transform(test[features])
        models = _fit_models(
            core_values, core["target_class"].to_numpy(), config, seed
        )
        model_metrics: dict[str, Any] = {}
        for name, model in models.items():
            calibration_raw = _ordered_probabilities(model, calibration_values)
            selection_raw = _ordered_probabilities(model, selection_values)
            test_raw = _ordered_probabilities(model, test_values)
            methods: dict[str, Any] = {}
            selection_metrics: dict[str, Any] = {}
            for method in ("platt", "isotonic"):
                calibrator = _fit_multiclass_calibrator(
                    method,
                    calibration_raw,
                    calibration_fit["target_class"].to_numpy(),
                    seed,
                    float(config["probability_floor"]),
                )
                selection_metrics[method] = _multiclass_metrics(
                    calibration_selection["target_class"].to_numpy(),
                    calibrator.predict(selection_raw),
                )
                methods[method] = _multiclass_metrics(
                    test["target_class"].to_numpy(),
                    calibrator.predict(test_raw),
                )
            selected = min(
                methods,
                key=lambda method: (
                    selection_metrics[method]["log_loss"],
                    selection_metrics[method]["brier"],
                ),
            )
            model_metrics[name] = {
                "selected_calibration": selected,
                "metrics": methods[selected],
                "calibrations": methods,
                "validation_calibrations": selection_metrics,
                "validation_metrics": selection_metrics[selected],
            }
        baseline = _baseline_probabilities(test)
        windows.append(
            {
                "test_season": test_season,
                "calibration_season": calibration_season,
                "training_seasons": prior[:-1],
                "count": len(test),
                "baseline": _multiclass_metrics(
                    test["target_class"].to_numpy(), baseline
                ),
                "models": model_metrics,
            }
        )
    summary: dict[str, Any] = {"windows": windows}
    for model in ("logistic_regression", "xgboost"):
        lifts = [
            window["baseline"]["log_loss"]
            - window["models"][model]["metrics"]["log_loss"]
            for window in windows
        ]
        summary[model] = {
            "windows": len(lifts),
            "better_windows": sum(value > 0 for value in lifts),
            "better_window_rate": (
                sum(value > 0 for value in lifts) / len(lifts) if lifts else 0.0
            ),
            "average_log_loss_lift": float(np.mean(lifts)) if lifts else None,
            "worst_log_loss_lift": min(lifts) if lifts else None,
            "average_validation_log_loss": (
                float(
                    np.mean(
                        [
                            window["models"][model]["validation_metrics"]["log_loss"]
                            for window in windows
                        ]
                    )
                )
                if windows
                else None
            ),
        }
    summary["selected_model"] = min(
        ("logistic_regression", "xgboost"),
        key=lambda model: (
            summary[model]["average_validation_log_loss"]
            if summary[model]["average_validation_log_loss"] is not None
            else math.inf,
            model,
        ),
    )
    return summary


def _fit_models(
    values: np.ndarray,
    targets: np.ndarray,
    config: dict[str, Any],
    seed: int,
) -> dict[str, Any]:
    logistic_config = config["logistic_regression"]
    logistic = LogisticRegression(
        C=float(logistic_config["C"]),
        max_iter=int(logistic_config["max_iter"]),
        class_weight="balanced",
        random_state=seed,
    )
    logistic.fit(values, targets)
    xgb_config = config["xgboost"]
    xgboost = XGBClassifier(
        objective="multi:softprob",
        num_class=3,
        eval_metric="mlogloss",
        tree_method="hist",
        random_state=seed,
        n_jobs=1,
        **xgb_config,
    )
    xgboost.fit(values, targets)
    return {"logistic_regression": logistic, "xgboost": xgboost}


def _fit_multiclass_calibrator(
    method: str,
    probabilities: np.ndarray,
    targets: np.ndarray,
    seed: int,
    probability_floor: float,
) -> MulticlassCalibrator:
    return MulticlassCalibrator(
        method=method,
        calibrators={
            label: fit_calibrator(
                method,
                probabilities[:, label],
                (targets == label).astype(int),
                seed + label,
            )
            for label in CLASSES
        },
        probability_floor=probability_floor,
    )


def _preprocessor() -> Pipeline:
    return Pipeline(
        [
            ("imputer", SimpleImputer(strategy="median", keep_empty_features=True)),
            ("scaler", StandardScaler()),
        ]
    )


def _ordered_probabilities(model: Any, values: np.ndarray) -> np.ndarray:
    raw = np.asarray(model.predict_proba(values), dtype=float)
    ordered = np.zeros((raw.shape[0], 3), dtype=float)
    for index, label in enumerate(model.classes_):
        ordered[:, int(label)] = raw[:, index]
    return ordered


def _multiclass_metrics(
    targets: np.ndarray, probabilities: np.ndarray
) -> dict[str, Any]:
    labels = np.asarray(targets, dtype=int)
    values = np.asarray(probabilities, dtype=float)
    values = values / values.sum(axis=1, keepdims=True)
    one_hot = np.eye(3)[labels]
    return {
        "count": int(labels.size),
        "accuracy": float(accuracy_score(labels, values.argmax(axis=1))),
        "log_loss": float(log_loss(labels, values, labels=CLASSES)),
        "brier": float(np.mean(np.sum((values - one_hot) ** 2, axis=1))),
        "tie_rate_actual": float(np.mean(labels == 1)),
        "tie_probability_mean": float(np.mean(values[:, 1])),
    }


def _baseline_probabilities(frame: pd.DataFrame) -> np.ndarray:
    tie = (
        frame[["feature_home_tie_rate", "feature_away_tie_rate"]]
        .mean(axis=1)
        .fillna(0.20)
        .clip(0.05, 0.50)
        .to_numpy(dtype=float)
    )
    conditional_home = (
        frame["feature_elo_home_win_probability"]
        .fillna(0.50)
        .clip(0.05, 0.95)
        .to_numpy(dtype=float)
    )
    decided = 1 - tie
    return np.column_stack(
        [decided * (1 - conditional_home), tie, decided * conditional_home]
    )


def _probability_buckets(
    targets: np.ndarray, probabilities: np.ndarray
) -> list[dict[str, Any]]:
    labels = np.asarray(targets, dtype=int)
    values = np.asarray(probabilities, dtype=float)
    confidence = values.max(axis=1)
    predicted = values.argmax(axis=1)
    rows: list[dict[str, Any]] = []
    for lower, upper in ((0.0, 0.4), (0.4, 0.5), (0.5, 0.6), (0.6, 0.7), (0.7, 1.01)):
        mask = (confidence >= lower) & (confidence < upper)
        if not np.any(mask):
            continue
        rows.append(
            {
                "lower": lower,
                "upper": min(1.0, upper),
                "count": int(mask.sum()),
                "mean_confidence": float(confidence[mask].mean()),
                "accuracy": float(np.mean(predicted[mask] == labels[mask])),
            }
        )
    return rows


def _quote_evaluation(
    frame: pd.DataFrame,
    probabilities: np.ndarray,
    indices: np.ndarray,
) -> dict[str, Any]:
    required = {"market_home_price", "market_away_price"}
    if not required.issubset(frame.columns):
        return {"decisions": 0, "roi": None, "clv": None}
    holdout = frame.loc[indices].reset_index(drop=True)
    profits: list[float] = []
    for index, row in holdout.iterrows():
        away, _, home = probabilities[index]
        side = "home" if home >= away else "away"
        price = row.get(f"market_{side}_price")
        if pd.isna(price):
            continue
        win_probability = home if side == "home" else away
        loss_probability = away if side == "home" else home
        profit = float(price) / 100 if price > 0 else 100 / abs(float(price))
        expected_value = win_probability * profit - loss_probability
        if expected_value <= 0:
            continue
        actual = int(row["target_class"])
        if actual == 1:
            profits.append(0.0)
        elif (side == "home" and actual == 2) or (side == "away" and actual == 0):
            profits.append(profit)
        else:
            profits.append(-1.0)
    return {
        "decisions": len(profits),
        "roi": float(np.mean(profits)) if profits else None,
        "clv": None,
    }


def _promotion_summary(
    markets: dict[str, Any], criteria: dict[str, Any]
) -> dict[str, Any]:
    decisions: dict[str, Any] = {}
    for market, evaluation in markets.items():
        rolling = evaluation["rolling"]
        model = evaluation["selected_model"]
        summary = rolling.get(model, {})
        windows = int(summary.get("windows", 0))
        rate = float(summary.get("better_window_rate", 0.0))
        worst = summary.get("worst_log_loss_lift")
        quotes = evaluation["quote_evaluation"]
        eligible = (
            windows >= int(criteria["minimum_windows"])
            and rate >= float(criteria["minimum_better_window_rate"])
            and worst is not None
            and float(worst) >= -float(criteria["maximum_worst_log_loss_regression"])
            and int(quotes["decisions"]) >= int(criteria["minimum_quote_decisions"])
            and quotes["roi"] is not None
            and float(quotes["roi"]) > 0
        )
        decisions[market] = {
            "promotion_eligible": eligible,
            "windows": windows,
            "better_window_rate": rate,
            "worst_log_loss_lift": worst,
            "quote_decisions": quotes["decisions"],
            "quote_roi": quotes["roi"],
            "blocked_reasons": [
                reason
                for condition, reason in (
                    (windows < int(criteria["minimum_windows"]), "insufficient_windows"),
                    (rate < float(criteria["minimum_better_window_rate"]), "unstable_vs_elo"),
                    (
                        worst is None
                        or float(worst)
                        < -float(criteria["maximum_worst_log_loss_regression"]),
                        "worst_window_regression",
                    ),
                    (
                        int(quotes["decisions"])
                        < int(criteria["minimum_quote_decisions"]),
                        "insufficient_historical_quotes",
                    ),
                    (
                        quotes["roi"] is None or float(quotes["roi"] or 0) <= 0,
                        "positive_roi_not_proven",
                    ),
                )
                if condition
            ],
        }
    return {"markets": decisions, "promoted_markets": []}


def _validate_period_frame(frame: pd.DataFrame, features: list[str]) -> None:
    required = {
        "game_id",
        "season",
        "market_type",
        "game_start_at",
        "target_class",
        *features,
    }
    missing = sorted(required.difference(frame.columns))
    if missing:
        raise ValueError("Period dataset is missing columns: " + ", ".join(missing))
    unknown_markets = sorted(set(frame["market_type"]).difference(MARKETS))
    if unknown_markets:
        raise ValueError("Unsupported period markets: " + ", ".join(unknown_markets))
    targets = set(int(value) for value in frame["target_class"].unique())
    if not targets.issubset({0, 1, 2}):
        raise ValueError("Period target_class must contain only 0, 1, or 2.")
    frame["game_start_at"] = pd.to_datetime(frame["game_start_at"], utc=True)


def _read_frame(path: str | Path) -> pd.DataFrame:
    resolved = Path(path).expanduser().resolve()
    if resolved.suffix.lower() == ".json":
        payload = json.loads(resolved.read_text(encoding="utf-8"))
        return pd.DataFrame(payload if isinstance(payload, list) else [payload])
    if resolved.suffix.lower() == ".csv":
        return pd.read_csv(resolved)
    raise ValueError("Period inference input must be JSON or CSV.")


def _american_price(probability: float) -> int:
    clipped = min(1 - 1e-6, max(1e-6, probability))
    if clipped >= 0.5:
        return int(round(-100 * clipped / (1 - clipped)))
    return int(round(100 * (1 - clipped) / clipped))


def _entropy(probabilities: np.ndarray) -> float:
    values = np.clip(np.asarray(probabilities, dtype=float), 1e-12, 1.0)
    return float(-np.sum(values * np.log(values)) / math.log(3))


def _write_json(path: Path, value: Any) -> None:
    path = path.expanduser().resolve()
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


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


def _source_hash() -> str:
    root = Path(__file__).resolve().parents[2]
    digest = hashlib.sha256()
    paths = [
        root / "pyproject.toml",
        root / "requirements.lock.txt",
        *sorted((root / "config").glob("*.yaml")),
        *sorted((root / "src").rglob("*.py")),
    ]
    for path in paths:
        if not path.is_file():
            continue
        digest.update(str(path.relative_to(root)).encode("utf-8"))
        digest.update(b"\0")
        digest.update(path.read_bytes())
        digest.update(b"\0")
    return digest.hexdigest()
