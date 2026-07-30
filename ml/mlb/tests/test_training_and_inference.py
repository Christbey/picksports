from __future__ import annotations

import hashlib
import json
from pathlib import Path

import pytest

from picksports_mlb_ml.inference import InferenceBundle
from picksports_mlb_ml.pipeline import evaluate_rolling, train


def test_training_writes_compatible_bundle_and_inference_contract(
    synthetic_csv: Path,
    synthetic_frame,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    result = train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="mlb-synthetic-run",
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
    }
    assert expected_files == {
        str(path.relative_to(run_dir))
        for path in run_dir.rglob("*")
        if path.is_file()
    }

    manifest = json.loads((run_dir / "manifest.json").read_text(encoding="utf-8"))
    evaluation = json.loads(
        (run_dir / "evaluation.json").read_text(encoding="utf-8")
    )
    assert manifest["model_type"] == "mlb_tabular_bundle"
    assert manifest["package"] == "picksports_mlb_ml"
    assert manifest["module"] == "picksports_mlb_ml"
    assert evaluation["report_type"] == "mlb_tabular_walk_forward_evaluation"
    assert evaluation["rolling_weekly"]["summary"]["window_count"] == 2
    assert evaluation["promotion_summary"]["public_promotion_allowed"] is False
    assert set(manifest["selected_calibrators"].values()) <= {"platt", "isotonic"}
    assert all(
        len(descriptor["sha256"]) == 64
        for descriptor in manifest["artifacts"].values()
    )

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
    assert 0 <= output["uncertainty"] <= 1
    assert output["model_run_id"] == "mlb-synthetic-run"

    without_market = synthetic_frame.tail(1).drop(
        columns=[
            "feature_market_home_win_probability",
            "feature_market_home_spread",
            "feature_market_total",
        ]
    )
    nullable_output = InferenceBundle.load(run_dir).predict(without_market)[0]
    assert nullable_output["home_win_probability"] >= 0
    assert nullable_output["home_cover_probability"] is None
    assert nullable_output["over_probability"] is None

    prediction_example = json.loads(
        (run_dir / "prediction_example.json").read_text(encoding="utf-8")
    )
    assert set(prediction_example) == set(output)


def test_artifact_inventory_detects_tampering(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    result = train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="tamper-run",
    )
    run_dir = Path(result["run_dir"])
    with (run_dir / "evaluation.json").open("a", encoding="utf-8") as handle:
        handle.write("tampered")

    with pytest.raises(ValueError, match="mismatch"):
        InferenceBundle.load(run_dir)


def test_feature_schema_must_match_manifest_semantic_hash(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    result = train(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_dir=tmp_path / "artifacts",
        run_id="schema-tamper-run",
    )
    run_dir = Path(result["run_dir"])
    schema_file = run_dir / "feature_schema.yaml"
    schema_file.write_text(
        schema_file.read_text(encoding="utf-8").replace(
            "mlb-pregame-ml-v1", "mlb-pregame-ml-v1-tampered", 1
        ),
        encoding="utf-8",
    )
    manifest_file = run_dir / "manifest.json"
    manifest = json.loads(manifest_file.read_text(encoding="utf-8"))
    manifest["artifacts"]["feature_schema.yaml"] = {
        "sha256": hashlib.sha256(schema_file.read_bytes()).hexdigest(),
        "bytes": schema_file.stat().st_size,
    }
    manifest_file.write_text(
        json.dumps(manifest, indent=2, sort_keys=True) + "\n",
        encoding="utf-8",
    )

    with pytest.raises(ValueError, match="schema hash"):
        InferenceBundle.load(run_dir)


def test_training_rejects_unsafe_run_id(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    with pytest.raises(ValueError, match="safe UUID or slug"):
        train(
            input_path=synthetic_csv,
            schema_path=schema_path,
            output_dir=tmp_path / "artifacts",
            run_id="../outside",
        )


def test_rolling_command_emits_weekly_report(
    synthetic_csv: Path,
    schema_path: Path,
    tmp_path: Path,
) -> None:
    output = tmp_path / "rolling.json"
    report = evaluate_rolling(
        input_path=synthetic_csv,
        schema_path=schema_path,
        output_path=output,
    )

    assert output.is_file()
    assert report["report_type"] == "mlb_tabular_walk_forward_evaluation"
    assert len(report["windows"]) == 2
    assert all(
        window["train_end_at"] < window["calibration_start_at"]
        and window["calibration_end_at"] < window["test_start_at"]
        for window in report["windows"]
    )
