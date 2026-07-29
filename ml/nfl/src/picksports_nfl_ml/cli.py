from __future__ import annotations

import argparse
import json
from pathlib import Path
from typing import Sequence

import pandas as pd

from picksports_nfl_ml.data import load_immutable_dataset
from picksports_nfl_ml.experiments import diagnose
from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.pipeline import train
from picksports_nfl_ml.schema import FeatureSchema


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="picksports-nfl-ml",
        description=(
            "Train and run point-in-time-safe NFL tabular prediction models."
        ),
    )
    subparsers = parser.add_subparsers(dest="command", required=True)

    validate_parser = subparsers.add_parser(
        "validate",
        help="Validate and hash an immutable CSV or Parquet export.",
    )
    _add_dataset_arguments(validate_parser)

    train_parser = subparsers.add_parser(
        "train",
        help=(
            "Run walk-forward evaluation and save versioned classifiers, "
            "regressors, calibrators, and preprocessing artifacts."
        ),
    )
    _add_dataset_arguments(train_parser)
    train_parser.add_argument(
        "--output-dir",
        required=True,
        help="Parent directory for the versioned model run.",
    )
    train_parser.add_argument(
        "--run-id",
        help="Optional caller-supplied model run UUID or stable run identifier.",
    )
    train_parser.add_argument(
        "--seed",
        type=int,
        help="Override the deterministic seed declared by the feature schema.",
    )
    train_parser.add_argument(
        "--tune",
        action=argparse.BooleanOptionalAction,
        default=None,
        help="Enable or disable chronology-safe Optuna tuning.",
    )
    train_parser.add_argument(
        "--tuning-trials",
        type=int,
        help="Override Optuna trials per XGBoost model.",
    )
    train_parser.add_argument(
        "--tuning-timeout-seconds",
        type=int,
        help="Override the timeout for each Optuna study.",
    )
    train_parser.add_argument(
        "--explain",
        action=argparse.BooleanOptionalAction,
        default=None,
        help="Enable or disable SHAP artifacts.",
    )
    train_parser.add_argument(
        "--shap-max-rows",
        type=int,
        help="Limit the deterministic training sample used for SHAP.",
    )

    diagnose_parser = subparsers.add_parser(
        "diagnose",
        help=(
            "Diagnose a held-out season with calibrated blends, feature-group "
            "ablations, drift segments, and rolling training windows."
        ),
    )
    _add_dataset_arguments(diagnose_parser)
    diagnose_parser.add_argument(
        "--output",
        required=True,
        help="Destination JSON diagnostic report.",
    )
    diagnose_parser.add_argument(
        "--target-season",
        type=int,
        default=2024,
        help="Held-out season to investigate (default: 2024).",
    )
    diagnose_parser.add_argument(
        "--seed",
        type=int,
        help="Override the deterministic seed declared by the feature schema.",
    )
    diagnose_parser.add_argument(
        "--training-windows",
        default="expanding,4,5,6",
        help=(
            "Comma-separated training histories. Use expanding or a season "
            "count such as 4,5,6."
        ),
    )
    diagnose_parser.add_argument(
        "--ablations",
        action=argparse.BooleanOptionalAction,
        default=True,
        help="Enable or disable feature-group removal experiments.",
    )

    predict_parser = subparsers.add_parser(
        "predict",
        help="Load a saved run and emit the NFL model output contract.",
    )
    predict_parser.add_argument(
        "--run-dir",
        required=True,
        help="Versioned artifact directory containing manifest.json.",
    )
    predict_parser.add_argument(
        "--input",
        required=True,
        help="JSON, CSV, or Parquet file containing one or more feature rows.",
    )
    return parser


def main(argv: Sequence[str] | None = None) -> None:
    parser = build_parser()
    arguments = parser.parse_args(argv)
    try:
        if arguments.command == "validate":
            schema = FeatureSchema.load(arguments.schema)
            frame, dataset_hash = load_immutable_dataset(
                arguments.input,
                schema,
                arguments.expected_dataset_sha256,
            )
            _print_json(
                {
                    "valid": True,
                    "rows": len(frame),
                    "seasons": sorted(
                        int(value) for value in frame[schema.season_column].unique()
                    ),
                    "dataset_hash": dataset_hash,
                    "feature_schema_hash": schema.hash,
                    "feature_schema_version": schema.version,
                }
            )
            return
        if arguments.command == "train":
            _print_json(
                train(
                    input_path=arguments.input,
                    schema_path=arguments.schema,
                    output_dir=arguments.output_dir,
                    expected_dataset_sha256=arguments.expected_dataset_sha256,
                    run_id=arguments.run_id,
                    seed=arguments.seed,
                    tuning_enabled=arguments.tune,
                    tuning_trials=arguments.tuning_trials,
                    tuning_timeout_seconds=arguments.tuning_timeout_seconds,
                    explanations_enabled=arguments.explain,
                    shap_max_rows=arguments.shap_max_rows,
                )
            )
            return
        if arguments.command == "diagnose":
            _print_json(
                diagnose(
                    input_path=arguments.input,
                    schema_path=arguments.schema,
                    output_path=arguments.output,
                    target_season=arguments.target_season,
                    expected_dataset_sha256=arguments.expected_dataset_sha256,
                    seed=arguments.seed,
                    training_windows=_parse_training_windows(
                        arguments.training_windows
                    ),
                    run_ablations=arguments.ablations,
                )
            )
            return
        if arguments.command == "predict":
            bundle = InferenceBundle.load(arguments.run_dir)
            _print_json(bundle.predict(_read_prediction_input(arguments.input)))
            return
    except Exception as error:
        parser.exit(1, f"error: {error}\n")


def _add_dataset_arguments(parser: argparse.ArgumentParser) -> None:
    parser.add_argument(
        "--input",
        required=True,
        help="Immutable trusted NFL CSV or Parquet export.",
    )
    parser.add_argument(
        "--schema",
        default="config/feature_schema.yaml",
        help="Declared YAML feature schema (default: config/feature_schema.yaml).",
    )
    parser.add_argument(
        "--expected-dataset-sha256",
        help="Fail unless the source file has this exact SHA-256.",
    )


def _read_prediction_input(path: str | Path) -> pd.DataFrame:
    resolved = Path(path).expanduser().resolve()
    suffix = resolved.suffix.lower()
    if suffix == ".json":
        with resolved.open("r", encoding="utf-8") as handle:
            payload = json.load(handle)
        return pd.DataFrame(payload if isinstance(payload, list) else [payload])
    if suffix == ".csv":
        return pd.read_csv(resolved)
    if suffix in {".parquet", ".pq"}:
        return pd.read_parquet(resolved)
    raise ValueError("Prediction input must be JSON, CSV, or Parquet.")


def _print_json(value: object) -> None:
    print(json.dumps(value, indent=2, sort_keys=True))


def _parse_training_windows(value: str) -> list[int | None]:
    windows: list[int | None] = []
    for item in value.split(","):
        normalized = item.strip().lower()
        if normalized in {"expanding", "all", "none"}:
            windows.append(None)
            continue
        try:
            window = int(normalized)
        except ValueError as error:
            raise ValueError(
                f"Invalid training window '{item}'. Use expanding or an integer."
            ) from error
        if window < 2:
            raise ValueError("Rolling training windows must contain at least 2 seasons.")
        windows.append(window)
    if not windows:
        raise ValueError("At least one training window is required.")
    return windows


if __name__ == "__main__":
    main()
