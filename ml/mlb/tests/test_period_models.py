from __future__ import annotations

import json
from pathlib import Path

import pandas as pd
import yaml

from picksports_mlb_ml.periods import predict_period_models, train_period_models


def test_period_models_train_chronologically_and_return_three_outcomes(
    tmp_path: Path,
) -> None:
    source_schema = (
        Path(__file__).parents[1] / "config" / "period_feature_schema.yaml"
    )
    schema = yaml.safe_load(source_schema.read_text(encoding="utf-8"))
    schema["training"]["xgboost"]["n_estimators"] = 8
    schema["training"]["xgboost"]["max_depth"] = 2
    schema["training"]["promotion"]["minimum_quote_decisions"] = 1
    schema_path = tmp_path / "schema.yaml"
    schema_path.write_text(yaml.safe_dump(schema), encoding="utf-8")

    rows: list[dict[str, object]] = []
    game_id = 1
    features = list(schema["features"])
    for market_index, market in enumerate(
        ("first_3_moneyline", "first_5_moneyline")
    ):
        for season in range(2021, 2026):
            for game in range(15):
                target = game % 3
                row: dict[str, object] = {
                    "game_id": game_id,
                    "season": season,
                    "market_type": market,
                    "game_start_at": f"{season}-04-{game + 1:02d}T18:00:00Z",
                    "target_class": target,
                    "market_home_price": -105,
                    "market_away_price": -105,
                }
                for feature_index, feature in enumerate(features):
                    row[feature] = (
                        target * 0.4
                        + game * 0.02
                        + feature_index * 0.001
                        + market_index * 0.1
                    )
                row["feature_period_innings"] = 3 if market_index == 0 else 5
                row["feature_market_type_f3"] = 1 if market_index == 0 else 0
                row["feature_market_type_f5"] = 0 if market_index == 0 else 1
                rows.append(row)
                game_id += 1

    dataset = tmp_path / "period.csv"
    pd.DataFrame(rows).to_csv(dataset, index=False)
    bundle = tmp_path / "period.joblib"
    evaluation = tmp_path / "evaluation.json"
    manifest = tmp_path / "manifest.json"
    result = train_period_models(
        input_path=dataset,
        schema_path=schema_path,
        output_path=bundle,
        evaluation_path=evaluation,
        manifest_path=manifest,
        seed=7,
    )

    report = json.loads(evaluation.read_text(encoding="utf-8"))
    trained_manifest = json.loads(manifest.read_text(encoding="utf-8"))
    assert trained_manifest["dataset_rows"] == len(rows)
    assert len(trained_manifest["source_hash"]) == 64
    assert trained_manifest["dependencies"]["scikit-learn"] == "1.5.2"
    assert trained_manifest["dependencies"]["xgboost"] == "2.1.3"
    for market in ("first_3_moneyline", "first_5_moneyline"):
        windows = report["markets"][market]["rolling"]["windows"]
        assert [window["test_season"] for window in windows] == [2023, 2024, 2025]
        assert all(
            window["calibration_season"] < window["test_season"]
            for window in windows
        )

    prediction_input = tmp_path / "prediction.json"
    prediction_input.write_text(
        json.dumps(
            [
                {
                    "market_type": market,
                    **{
                        feature: rows[0][feature]
                        for feature in features
                    },
                }
                for market in ("first_3_moneyline", "first_5_moneyline")
            ]
        ),
        encoding="utf-8",
    )
    predictions = predict_period_models(bundle, prediction_input)

    assert len(predictions) == 2
    for prediction in predictions:
        probability_sum = sum(
            prediction[key]
            for key in (
                "away_win_probability",
                "tie_probability",
                "home_win_probability",
            )
        )
        assert abs(probability_sum - 1.0) < 0.00001
        assert prediction["model_run_id"] == result["model_run_id"]
        assert prediction["artifact_id"] == result["artifact_id"]
        assert prediction["dataset_hash"] == result["dataset_hash"]
