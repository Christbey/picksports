from __future__ import annotations

from picksports_nfl_ml.cli import build_parser


def test_cli_help_lists_operational_commands() -> None:
    help_text = build_parser().format_help()

    assert "validate" in help_text
    assert "train" in help_text
    assert "diagnose" in help_text
    assert "predict" in help_text


def test_train_cli_exposes_resource_controls() -> None:
    parser = build_parser()
    arguments = parser.parse_args(
        [
            "train",
            "--input",
            "dataset.csv",
            "--output-dir",
            "artifacts",
            "--no-tune",
            "--no-explain",
            "--tuning-trials",
            "2",
            "--shap-max-rows",
            "16",
        ]
    )

    assert arguments.tune is False
    assert arguments.explain is False
    assert arguments.tuning_trials == 2
    assert arguments.shap_max_rows == 16


def test_diagnose_cli_exposes_chronological_experiment_controls() -> None:
    parser = build_parser()
    arguments = parser.parse_args(
        [
            "diagnose",
            "--input",
            "dataset.csv",
            "--output",
            "diagnostic.json",
            "--target-season",
            "2024",
            "--training-windows",
            "expanding,4,5",
            "--no-ablations",
        ]
    )

    assert arguments.target_season == 2024
    assert arguments.training_windows == "expanding,4,5"
    assert arguments.ablations is False
