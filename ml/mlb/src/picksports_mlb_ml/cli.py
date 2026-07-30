from __future__ import annotations

import argparse
import json
from pathlib import Path

import pandas as pd

from picksports_mlb_ml.data import load_immutable_dataset
from picksports_mlb_ml.inference import InferenceBundle
from picksports_mlb_ml.pipeline import evaluate_rolling, train
from picksports_mlb_ml.schema import FeatureSchema


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(
        prog="picksports-mlb-ml",
        description="Train and run point-in-time-safe Picksports MLB models.",
    )
    commands = parser.add_subparsers(dest="command", required=True)

    validate_parser = commands.add_parser("validate")
    _add_dataset_arguments(validate_parser)

    train_parser = commands.add_parser("train")
    _add_dataset_arguments(train_parser)
    train_parser.add_argument("--output-dir", required=True)
    train_parser.add_argument("--run-id")
    train_parser.add_argument("--seed", type=int)

    evaluate_parser = commands.add_parser("evaluate-rolling")
    _add_dataset_arguments(evaluate_parser)
    evaluate_parser.add_argument("--output")
    evaluate_parser.add_argument("--seed", type=int)

    predict_parser = commands.add_parser("predict")
    predict_parser.add_argument("--run-dir", required=True)
    predict_parser.add_argument("--input", required=True)
    return parser


def main() -> None:
    parser = build_parser()
    arguments = parser.parse_args()
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
                    "dataset_hash": dataset_hash,
                    "feature_schema_hash": schema.hash,
                    "start_at": frame[schema.time_column].min().isoformat(),
                    "end_at": frame[schema.time_column].max().isoformat(),
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
                )
            )
            return
        if arguments.command == "evaluate-rolling":
            _print_json(
                evaluate_rolling(
                    input_path=arguments.input,
                    schema_path=arguments.schema,
                    output_path=arguments.output,
                    expected_dataset_sha256=arguments.expected_dataset_sha256,
                    seed=arguments.seed,
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
        help="Immutable trusted MLB CSV or Parquet export.",
    )
    parser.add_argument(
        "--schema",
        default="config/feature_schema.yaml",
        help="Declared YAML feature schema.",
    )
    parser.add_argument("--expected-dataset-sha256")


def _read_prediction_input(path: str | Path) -> pd.DataFrame:
    resolved = Path(path).expanduser().resolve()
    if resolved.suffix.lower() == ".json":
        payload = json.loads(resolved.read_text(encoding="utf-8"))
        return pd.DataFrame(payload if isinstance(payload, list) else [payload])
    if resolved.suffix.lower() == ".csv":
        return pd.read_csv(resolved)
    if resolved.suffix.lower() in {".parquet", ".pq"}:
        return pd.read_parquet(resolved)
    raise ValueError("Prediction input must be JSON, CSV, or Parquet.")


def _print_json(value: object) -> None:
    print(json.dumps(value, indent=2, sort_keys=True))
