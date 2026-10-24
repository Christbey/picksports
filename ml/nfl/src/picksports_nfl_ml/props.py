"""Offline, date-ordered player-prop benchmark. Never selects a production model."""
from __future__ import annotations

import json
from pathlib import Path
from typing import Any

import numpy as np
import pandas as pd
import sklearn
import xgboost
from sklearn.impute import SimpleImputer
from sklearn.linear_model import Ridge
from sklearn.pipeline import make_pipeline
from sklearn.preprocessing import StandardScaler
from xgboost import XGBRegressor

from picksports_nfl_ml.hashing import sha256_file

FEATURES = [
    "feature_history_games", "feature_current_season_games", "feature_is_home",
    "feature_season_mean", "feature_recent5_mean", "feature_recent10_mean",
    "feature_recent_weighted_mean", "feature_std_dev", "feature_opponent_games",
    "feature_opponent_mean", "feature_venue_games", "feature_venue_mean",
    "feature_recent_passing_attempts", "feature_recent_rushing_attempts",
    "feature_recent_receiving_targets",
]
COUNT_MARKETS = {
    "player_pass_attempts", "player_pass_completions", "player_pass_tds",
    "player_pass_interceptions", "player_rush_attempts", "player_receptions",
    "player_anytime_td",
}
MODELS = ("recent5_mean", "legacy_neutral", "ridge", "xgboost")
RIDGE_ALPHA = 10.0
BOOSTED_PARAMETERS = {
    "objective": "reg:squarederror", "tree_method": "hist", "n_estimators": 80,
    "max_depth": 2, "learning_rate": 0.05, "min_child_weight": 10,
    "reg_lambda": 10.0, "subsample": 1.0, "colsample_bytree": 1.0, "n_jobs": 1,
}


def validate_samples(frame: pd.DataFrame) -> pd.DataFrame:
    required = set(FEATURES) | {
        "game_id", "player_id", "market", "game_start_at",
        "history_max_game_start_at", "actual", "legacy_mean", "recent5_mean",
        "quote_line", "quote_observed_at",
    }
    missing = required - set(frame.columns)
    if missing:
        raise ValueError(f"Missing prop benchmark columns: {sorted(missing)}")
    unexpected = {c for c in frame if c.startswith("feature_")} - set(FEATURES)
    if unexpected:
        raise ValueError(f"Unapproved feature columns: {sorted(unexpected)}")
    frame = frame.copy()
    for column in ["game_start_at", "history_max_game_start_at", "quote_observed_at"]:
        frame[column] = pd.to_datetime(frame[column], utc=True, errors="raise")
    if frame[["game_id", "player_id", "market", "game_start_at", "history_max_game_start_at"]].isna().any().any():
        raise ValueError("Sample identity and history timestamps must be present.")
    if frame.duplicated(["game_id", "player_id", "market"]).any():
        raise ValueError("Duplicate player/game/market samples are not independent observations.")
    if (frame.groupby("game_id")["game_start_at"].nunique() != 1).any():
        raise ValueError("Every sample for one game must share its kickoff.")
    if (frame["history_max_game_start_at"] >= frame["game_start_at"]).any():
        raise ValueError("Feature history must precede the target kickoff.")
    if (frame["history_max_game_start_at"] + pd.Timedelta(hours=24) >= frame["game_start_at"]).any():
        raise ValueError("Feature history must mature for 24 hours before target kickoff.")
    for column in FEATURES + ["actual", "legacy_mean", "recent5_mean", "quote_line"]:
        frame[column] = pd.to_numeric(frame[column], errors="raise")
        if np.isinf(frame[column].to_numpy(dtype=float)).any():
            raise ValueError(f"Infinite values in {column}.")
    if frame[["actual", "legacy_mean", "recent5_mean"]].isna().any().any():
        raise ValueError("Actuals and comparator forecasts must be numeric.")
    quoted = frame["quote_line"].notna()
    if (quoted & (frame["quote_observed_at"].isna()
                  | (frame["quote_observed_at"] > frame["game_start_at"])
                  | (frame["quote_observed_at"] < frame["game_start_at"] - pd.Timedelta(hours=36)))).any():
        raise ValueError("Quote references must be observed within 36 hours before kickoff.")
    return frame.sort_values(["game_start_at", "game_id", "player_id", "market"]).reset_index(drop=True)


def chronological_folds(frame: pd.DataFrame, minimum_training_rows: int):
    """Group calendar weeks and require conservative 24h label maturity before each fold."""
    if minimum_training_rows < 2:
        raise ValueError("At least two training observations are required.")
    weeks = frame["game_start_at"].dt.tz_convert("America/Chicago").dt.strftime("%G-%V")
    for week in sorted(weeks.unique()):
        test = frame.loc[weeks == week]
        first_kickoff = test["game_start_at"].min()
        train = frame.loc[(weeks < week) & (frame["game_start_at"] + pd.Timedelta(hours=24) < first_kickoff)]
        if len(train) >= minimum_training_rows:
            yield week, train, test


def _metrics(actual: np.ndarray, predicted: np.ndarray) -> dict[str, float]:
    errors = predicted - actual
    return {"mae": float(np.mean(np.abs(errors))), "rmse": float(np.sqrt(np.mean(errors ** 2))),
            "bias": float(np.mean(errors))}


def fit_estimators(train: pd.DataFrame, seed: int) -> dict[str, Any]:
    # Imputation and scaling are fitted exclusively on earlier training observations.
    ridge = make_pipeline(SimpleImputer(strategy="median", keep_empty_features=True),
                          StandardScaler(), Ridge(alpha=RIDGE_ALPHA))
    boosted = XGBRegressor(**BOOSTED_PARAMETERS, random_state=seed)
    ridge.fit(train[FEATURES], train["actual"])
    boosted.fit(train[FEATURES], train["actual"])
    return {"ridge": ridge, "xgboost": boosted}


def benchmark(frame: pd.DataFrame, minimum_training_rows: int = 50, seed: int = 42) -> dict[str, Any]:
    frame = validate_samples(frame)
    markets: dict[str, Any] = {}
    for market, samples in frame.groupby("market", sort=True):
        predictions: list[pd.DataFrame] = []
        folds: list[dict[str, Any]] = []
        for week, train, test in chronological_folds(samples, minimum_training_rows):
            estimators = fit_estimators(train, seed)
            ridge, boosted = estimators["ridge"], estimators["xgboost"]
            scored = test[["game_id", "actual", "quote_line"]].copy()
            scored["recent5_mean"] = test["recent5_mean"]
            scored["legacy_neutral"] = test["legacy_mean"]
            scored["ridge"] = ridge.predict(test[FEATURES])
            scored["xgboost"] = boosted.predict(test[FEATURES])
            if market in COUNT_MARKETS:
                scored[["ridge", "xgboost"]] = scored[["ridge", "xgboost"]].clip(lower=0)
            if not np.isfinite(scored[list(MODELS)].to_numpy()).all():
                raise ValueError("Estimator produced non-finite predictions.")
            predictions.append(scored)
            folds.append({"week": week, "train_rows": len(train), "test_rows": len(test),
                          "train_games": int(train["game_id"].nunique()),
                          "test_games": int(test["game_id"].nunique()),
                          "last_training_kickoff": train["game_start_at"].max().isoformat(),
                          "first_test_kickoff": test["game_start_at"].min().isoformat(),
                          "models": {model: _metrics(scored["actual"].to_numpy(dtype=float), scored[model].to_numpy(dtype=float)) for model in MODELS}})
        if not predictions:
            markets[str(market)] = {"status": "insufficient_training_history", "available_rows": len(samples),
                                    "evaluated_rows": 0, "models": {}, "folds": []}
            continue
        scored = pd.concat(predictions, ignore_index=True)
        actual = scored["actual"].to_numpy(dtype=float)
        models = {model: _metrics(actual, scored[model].to_numpy(dtype=float)) for model in MODELS}
        quoted = scored.loc[scored["quote_line"].notna()]
        quote_reference = {"n": len(quoted), "models": {}}
        if not quoted.empty:
            quote_actual = quoted["actual"].to_numpy(dtype=float)
            quote_reference["line_as_point_reference"] = _metrics(quote_actual, quoted["quote_line"].to_numpy(dtype=float))
            quote_reference["models"] = {model: _metrics(quote_actual, quoted[model].to_numpy(dtype=float)) for model in MODELS}
        markets[str(market)] = {"status": "retrospective_only", "available_rows": len(samples),
                                "evaluated_rows": len(scored), "evaluated_games": int(scored["game_id"].nunique()),
                                "models": models, "quote_reference": quote_reference, "folds": folds}
    return {"comparison": "nfl_prop_walk_forward_benchmark_v1", "production_promotion": False,
            "minimum_training_rows": minimum_training_rows, "seed": seed, "features": FEATURES,
            "training_parameters": {"ridge_alpha": RIDGE_ALPHA, "xgboost": BOOSTED_PARAMETERS},
            "library_versions": {"numpy": np.__version__, "pandas": pd.__version__,
                                 "scikit_learn": sklearn.__version__, "xgboost": xgboost.__version__},
            "markets": markets, "protocol": {
                "folds": "Expanding training; ISO calendar weeks in America/Chicago; all quotes for a game share a fold.",
                "label_maturity": "Training kickoffs plus a conservative 24 hours must precede the first test kickoff.",
                "feature_maturity": "Historical feature-game kickoffs plus 24 hours must precede each target kickoff.",
                "hyperparameters": "Fixed before this experiment; no test-set tuning or automatic model selection.",
                "provenance": "Retrospective stored-stat reconstruction, not original source revisions or an untouched holdout.",
                "legacy": "Matched-history neutral baseline, not a replay of full production contextual forecasts.",
                "quote_reference": "Stored pregame line is a point reference, not necessarily an expected mean; no profitability claim.",
                "probabilities": "Not evaluated; no calibrated probabilities or betting approvals are produced.",
            }}


def run_benchmark(input_path: str | Path, output_path: str | Path, minimum_training_rows: int = 50,
                  seed: int = 42, expected_sha256: str | None = None) -> dict[str, Any]:
    source = Path(input_path).expanduser().resolve()
    destination = Path(output_path).expanduser().resolve()
    if source == destination:
        raise ValueError("Output must not overwrite the source dataset.")
    before = sha256_file(source)
    if expected_sha256 and before != expected_sha256.lower():
        raise ValueError("Dataset SHA-256 mismatch.")
    payload = json.loads(source.read_text(encoding="utf-8"))
    if payload.get("sample_schema_version") != "nfl-prop-benchmark-v1":
        raise ValueError("Unsupported prop sample schema.")
    result = benchmark(pd.DataFrame(payload["samples"]), minimum_training_rows, seed)
    if sha256_file(source) != before:
        raise ValueError("Dataset changed during evaluation.")
    result["implementation_sha256"] = sha256_file(Path(__file__))
    result["dataset_sha256"] = before
    result["source_metadata"] = payload.get("source_metadata", {})
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(result, indent=2, sort_keys=True, allow_nan=False) + "\n", encoding="utf-8")
    return result
