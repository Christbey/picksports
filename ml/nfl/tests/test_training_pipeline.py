from __future__ import annotations

import json
from pathlib import Path

import pandas as pd

from picksports_nfl_ml.inference import InferenceBundle
from picksports_nfl_ml.pipeline import train


def test_trains_versioned_artifacts_and_emits_output_contract(
    synthetic_csv: Path,
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    result = train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="synthetic-run",
    )
    run_dir = Path(result["run_dir"])

    expected_files = {
        "manifest.json",
        "evaluation.json",
        "prediction_example.json",
        "feature_schema.yaml",
        "preprocessor.joblib",
        "models/logistic_classifier.joblib",
        "models/xgboost_classifier.ubj",
        "models/xgboost_home_margin.ubj",
        "models/xgboost_total_points.ubj",
        "calibrators/logistic_regression_platt.joblib",
        "calibrators/logistic_regression_isotonic.joblib",
        "calibrators/xgboost_platt.joblib",
        "calibrators/xgboost_isotonic.joblib",
        "tuning/optuna_provenance.json",
        "explanations/global_shap_importance.json",
        "explanations/shap_values.parquet",
        "explanations/xgboost_feature_importance.json",
    }
    actual_files = {
        str(path.relative_to(run_dir)) for path in run_dir.rglob("*") if path.is_file()
    }
    assert expected_files.issubset(actual_files)

    with (run_dir / "evaluation.json").open("r", encoding="utf-8") as handle:
        evaluation = json.load(handle)
    assert evaluation["walk_forward"]["summary"]["window_count"] == 3
    assert evaluation["walk_forward"]["summary"][
        "challenger_better_window_count"
    ] <= 3
    assert "avg_brier_delta" in evaluation["walk_forward"]["summary"]
    assert "avg_log_loss_delta" in evaluation["walk_forward"]["summary"]
    assert evaluation["final_holdout"]["test_season"] == 2024
    assert "baselines" in evaluation["final_holdout"]
    assert evaluation["promotion_summary"]["public_promotion_allowed"] is False
    assert "avg_brier_delta" in evaluation["promotion_summary"][
        "baseline_comparisons"
    ]["current_picksports"]
    assert evaluation["tuning"]["test_season_access"] is False
    assert evaluation["tuning"]["artifact"]["sha256"]
    assert evaluation["explanations"]["artifacts"]["shap_values"]["sha256"]
    assert set(evaluation["final_holdout"]["classifiers"]) == {
        "logistic_regression",
        "xgboost",
        "blend",
    }
    for model_name in ("logistic_regression", "xgboost"):
        classifier = evaluation["final_holdout"]["classifiers"][model_name]
        assert set(classifier["calibration_selection"]) == {
            "uncalibrated",
            "platt",
            "isotonic",
        }

    output = InferenceBundle.load(run_dir).predict(synthetic_frame.tail(1))[0]
    assert set(output) == {
        "home_win_probability",
        "expected_home_margin",
        "expected_total",
        "home_cover_probability",
        "over_probability",
        "uncertainty",
        "model_run_id",
        "artifact_id",
        "dataset_hash",
        "feature_hash",
    }
    assert 0 <= output["home_win_probability"] <= 1
    assert 0 <= output["home_cover_probability"] <= 1
    assert 0 <= output["over_probability"] <= 1
    assert output["model_run_id"] == "synthetic-run"

    nullable_input = synthetic_frame.tail(1).drop(
        columns=["feature_market_home_spread", "feature_market_total"]
    )
    nullable_output = InferenceBundle.load(run_dir).predict(nullable_input)[0]
    assert nullable_output["home_cover_probability"] is None
    assert nullable_output["over_probability"] is None


def test_held_out_targets_cannot_change_model_selection(
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    original_path = tmp_path / "original.csv"
    changed_path = tmp_path / "changed-test-targets.csv"
    synthetic_frame.to_csv(original_path, index=False)
    changed = synthetic_frame.copy()
    test_rows = changed["season"] == changed["season"].max()
    changed.loc[test_rows, "target_home_win"] = (
        1 - changed.loc[test_rows, "target_home_win"]
    )
    changed.loc[test_rows, "target_home_margin"] *= -1
    changed.loc[test_rows, "target_hash"] = changed.loc[
        test_rows, "target_hash"
    ].map(lambda value: f"changed-{value}")
    changed.to_csv(changed_path, index=False)

    first = train(
        input_path=original_path,
        schema_path=schema_path,
        output_dir=tmp_path / "first",
        run_id="first",
    )
    second = train(
        input_path=changed_path,
        schema_path=schema_path,
        output_dir=tmp_path / "second",
        run_id="second",
    )
    first_manifest = json.loads(Path(first["manifest_path"]).read_text())
    second_manifest = json.loads(Path(second["manifest_path"]).read_text())

    assert first_manifest["champion_classifier"] == second_manifest[
        "champion_classifier"
    ]
    assert first_manifest["selected_calibrators"] == second_manifest[
        "selected_calibrators"
    ]
    assert first_manifest["classifier_blend"] == second_manifest[
        "classifier_blend"
    ]
