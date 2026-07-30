from __future__ import annotations

import copy
import json
from pathlib import Path
from typing import Any

import joblib
import pandas as pd

import picksports_mlb_ml.pipeline as pipeline


def test_deployment_refit_includes_newest_rows_after_holdout_evaluation(
    synthetic_csv: Path,
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
    monkeypatch,
) -> None:
    fit_events: list[dict[str, Any]] = []
    evaluation_events: list[dict[str, Any]] = []
    lifecycle_events: list[tuple[str, int]] = []
    real_fit_model_set = pipeline.fit_model_set
    real_evaluate_saved_model = pipeline._evaluate_saved_model

    def record_fit(frame, schema, seed, tuned_parameters=None):
        lifecycle_events.append(("fit", int(len(frame))))
        fit_events.append(
            {
                "game_ids": frame[schema.id_column].tolist(),
                "end_at": frame[schema.time_column].max().isoformat(),
            }
        )
        return real_fit_model_set(frame, schema, seed, tuned_parameters)

    def record_evaluation(models, calibration, fold, schema, blend):
        lifecycle_events.append(("evaluate", int(len(fold.test))))
        metrics = real_evaluate_saved_model(
            models,
            calibration,
            fold,
            schema,
            blend,
        )
        evaluation_events.append(
            {
                "test_game_ids": fold.test[schema.id_column].tolist(),
                "test_end_at": fold.test_end_at,
                "metrics": copy.deepcopy(metrics),
            }
        )
        return metrics

    monkeypatch.setattr(pipeline, "fit_model_set", record_fit)
    monkeypatch.setattr(
        pipeline,
        "_evaluate_saved_model",
        record_evaluation,
    )

    result = pipeline.train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="deployment-refit-run",
    )
    run_dir = Path(result["run_dir"])
    manifest = json.loads((run_dir / "manifest.json").read_text(encoding="utf-8"))
    evaluation = json.loads(
        (run_dir / "evaluation.json").read_text(encoding="utf-8")
    )

    latest_start = pd.to_datetime(
        synthetic_frame["game_start_at"],
        utc=True,
    ).max().isoformat()
    all_game_ids = synthetic_frame["game_id"].tolist()
    final_evaluation = evaluation_events[-1]

    assert lifecycle_events[-2][0] == "evaluate"
    assert lifecycle_events[-1] == ("fit", len(synthetic_frame))
    assert fit_events[-1]["game_ids"] == all_game_ids
    assert fit_events[-1]["end_at"] == latest_start
    assert max(final_evaluation["test_game_ids"]) == max(all_game_ids)
    assert final_evaluation["test_end_at"] == latest_start
    assert evaluation["final_holdout"]["classifiers"] == (
        final_evaluation["metrics"]["classifiers"]
    )
    assert evaluation["final_holdout"]["regressors"] == (
        final_evaluation["metrics"]["regressors"]
    )
    assert evaluation["final_holdout"]["baselines"] == (
        final_evaluation["metrics"]["baselines"]
    )

    refit = manifest["deployment_refit"]
    assert refit == evaluation["deployment_refit"]
    assert refit["training_cutoff_at"] == latest_start
    assert refit["eligible_row_count"] == len(synthetic_frame)
    assert refit["seasons"] == [2025, 2026]
    assert refit["observed_weeks"]["count"] == 32
    assert refit["observed_weeks"]["values"][-1] == "2025-11-03"
    assert refit["held_out_metrics_are_pre_refit"] is True
    assert refit["selection_strategy"]["selection_repeated_after_refit"] is False
    assert evaluation["final_holdout"]["metrics_provenance"] == (
        "pre_refit_untouched_chronological_holdout"
    )

    preprocessor = joblib.load(run_dir / "preprocessor.joblib")
    assert int(preprocessor.named_steps["scaler"].n_samples_seen_) == len(
        synthetic_frame
    )


def test_deployment_calibration_uses_pre_refit_out_of_sample_periods(
    synthetic_csv: Path,
    synthetic_frame: pd.DataFrame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    result = pipeline.train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="deployment-calibration-run",
    )
    run_dir = Path(result["run_dir"])
    manifest = json.loads((run_dir / "manifest.json").read_text(encoding="utf-8"))
    evaluation = json.loads(
        (run_dir / "evaluation.json").read_text(encoding="utf-8")
    )
    refit = manifest["deployment_refit"]
    strategy = refit["calibration_strategy"]
    starts = pd.to_datetime(synthetic_frame["game_start_at"], utc=True)
    calibration_start = pd.Timestamp(
        evaluation["final_holdout"]["calibration_start_at"]
    )

    expected_rows = int((starts >= calibration_start).sum())

    assert strategy["fit_row_count"] == expected_rows
    assert strategy["fit_start_at"] == (
        evaluation["final_holdout"]["calibration_start_at"]
    )
    assert strategy["fit_end_at"] == evaluation["final_holdout"]["test_end_at"]
    assert strategy["selected_methods"] == manifest["selected_calibrators"]
    assert "out-of-sample" in strategy["fit_prediction_source"]
    assert "pre-refit" in refit["held_out_metrics_provenance"]
