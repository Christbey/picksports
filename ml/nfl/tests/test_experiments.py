from __future__ import annotations

import json
from pathlib import Path

import pandas as pd

from picksports_nfl_ml.experiments import diagnose


def test_diagnoses_target_season_without_leaking_future_rows(
    synthetic_csv: Path,
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    output = tmp_path / "diagnostic.json"
    result = diagnose(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_path=output,
        target_season=2023,
        training_windows=[None],
        run_ablations=False,
    )

    report = json.loads(output.read_text(encoding="utf-8"))
    target = report["target_window"]

    assert result["target_season"] == 2023
    assert target["test_season"] == 2023
    assert target["calibration_season"] == 2022
    assert max(target["train_seasons"]) == 2021
    assert report["delta_convention"] == "baseline_minus_challenger"
    assert report["positive_delta_means"] == "challenger_better"
    assert set(target["win_probability"]["blend"]["weights"]) == {
        "logistic_regression",
        "xgboost",
        "current_picksports",
    }
    assert report["feature_group_ablations"]["enabled"] is False
    assert report["training_window_comparison"]["strategies"][0][
        "summary"
    ]["window_count"] == 3
    assert "season_phase" in report["failure_diagnostics"]["segments"]
    assert set(target["market_deltas"]) == {
        "win_probability",
        "home_margin",
        "total_points",
    }
