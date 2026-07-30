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

    assert first_manifest["model_type"] == "nfl_tabular_bundle"
    assert first_manifest["champion_classifier"] == second_manifest[
        "champion_classifier"
    ]
    assert first_manifest["selected_calibrators"] == second_manifest[
        "selected_calibrators"
    ]
    assert first_manifest["classifier_blend"] == second_manifest[
        "classifier_blend"
    ]


def test_deployment_refit_includes_partial_current_season_without_metric_leakage(
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    partial = _partial_current_season(synthetic_frame)
    original = pd.concat([synthetic_frame, partial], ignore_index=True)
    changed = original.copy()
    current_rows = changed["season"] == 2025
    changed.loc[current_rows, "target_home_win"] = (
        1 - changed.loc[current_rows, "target_home_win"]
    )
    changed.loc[current_rows, "target_home_margin"] += 35
    changed.loc[current_rows, "target_total_points"] += 25
    changed.loc[current_rows, "feature_elo_diff"] += 900
    changed.loc[current_rows, "target_hash"] = changed.loc[
        current_rows, "target_hash"
    ].map(lambda value: f"changed-{value}")
    changed.loc[current_rows, "feature_hash"] = changed.loc[
        current_rows, "feature_hash"
    ].map(lambda value: f"changed-{value}")

    original_path = tmp_path / "partial-current.csv"
    changed_path = tmp_path / "changed-partial-current.csv"
    original.to_csv(original_path, index=False)
    changed.to_csv(changed_path, index=False)
    first = train(
        input_path=original_path,
        schema_path=schema_path,
        output_dir=tmp_path / "first-refit",
        run_id="first-refit",
    )
    second = train(
        input_path=changed_path,
        schema_path=schema_path,
        output_dir=tmp_path / "second-refit",
        run_id="second-refit",
    )

    first_manifest = json.loads(Path(first["manifest_path"]).read_text())
    second_manifest = json.loads(Path(second["manifest_path"]).read_text())
    first_evaluation = json.loads(Path(first["evaluation_path"]).read_text())
    second_evaluation = json.loads(Path(second["evaluation_path"]).read_text())
    refit = first_manifest["deployment_refit"]

    assert first_evaluation["final_holdout"] == second_evaluation["final_holdout"]
    assert first_evaluation["walk_forward"] == second_evaluation["walk_forward"]
    assert first_manifest["champion_classifier"] == second_manifest[
        "champion_classifier"
    ]
    assert first_manifest["selected_calibrators"] == second_manifest[
        "selected_calibrators"
    ]
    assert first_manifest["classifier_blend"] == second_manifest[
        "classifier_blend"
    ]
    assert first_evaluation["final_holdout"]["test_season"] == 2024
    assert first_evaluation["final_holdout"]["test_season_status"] == "complete"
    assert first_evaluation["dataset"]["season_completeness"][-1]["complete"] is False

    assert refit["row_count"] == len(original)
    assert refit["seasons"][-1] == 2025
    assert first_manifest["evaluation_training_seasons"][-1] == 2022
    assert first_manifest["artifact_training_seasons"][-1] == 2025
    assert first_manifest["artifact_training_cutoff"] == refit["training_cutoff"]
    assert refit["partial_seasons_included"] == [2025]
    assert refit["rows_by_season"]["2025"] == len(partial)
    assert refit["selection_frozen_before_refit"]["source_held_out_test_season"] == 2024
    assert (
        refit["calibration_strategy"]["name"]
        == "reuse_pre_refit_chronological_calibrators"
    )
    assert "computed before deployment refit" in refit["pre_refit_metric_statement"]

    bundle = InferenceBundle.load(first["run_dir"])
    fitted_rows = bundle.preprocessor.named_steps["scaler"].n_samples_seen_
    assert int(fitted_rows) == len(original)
    assert first_manifest["artifacts"]["preprocessor.joblib"]["sha256"] != (
        second_manifest["artifacts"]["preprocessor.joblib"]["sha256"]
    )
    assert first_manifest["artifacts"]["models/xgboost_classifier.ubj"][
        "sha256"
    ] != second_manifest["artifacts"]["models/xgboost_classifier.ubj"]["sha256"]


def _partial_current_season(frame: pd.DataFrame) -> pd.DataFrame:
    partial = frame[frame["season"] == frame["season"].max()].head(4).copy()
    first_game_id = int(frame["game_id"].max()) + 1
    partial["season"] = 2025
    partial["game_id"] = range(first_game_id, first_game_id + len(partial))
    partial["snapshot_run_id"] = partial["game_id"].map(
        lambda game_id: f"snapshot-{game_id}"
    )
    starts = pd.date_range(
        "2025-09-01T18:00:00Z",
        periods=len(partial),
        freq="7D",
    )
    partial["game_start_at"] = [value.isoformat() for value in starts]
    partial["features_available_at"] = [
        (value - pd.Timedelta(hours=4)).isoformat() for value in starts
    ]
    partial["feature_week"] = range(1, len(partial) + 1)
    partial["feature_hash"] = partial["game_id"].map(
        lambda game_id: f"feature-hash-{game_id}"
    )
    partial["target_hash"] = partial["game_id"].map(
        lambda game_id: f"target-hash-{game_id}"
    )
    return partial
