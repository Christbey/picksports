from __future__ import annotations

from pathlib import Path

import pytest

from picksports_nfl_ml.data import DatasetIntegrityError, load_immutable_dataset
from picksports_nfl_ml.schema import FeatureSchema, SchemaError


def test_validates_and_hashes_immutable_csv(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, dataset_hash = load_immutable_dataset(synthetic_csv, schema)

    assert len(frame) == 112
    assert len(dataset_hash) == 64
    assert frame["season"].tolist() == sorted(frame["season"].tolist())


def test_rejects_undeclared_feature(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    frame, _ = load_immutable_dataset(synthetic_csv, schema)
    frame["feature_postgame_leak"] = 1
    frame.to_csv(synthetic_csv, index=False)

    with pytest.raises(SchemaError, match="undeclared feature"):
        load_immutable_dataset(synthetic_csv, schema)


def test_rejects_wrong_expected_hash(
    synthetic_csv: Path,
    schema_path: Path,
) -> None:
    with pytest.raises(DatasetIntegrityError, match="SHA-256 mismatch"):
        load_immutable_dataset(
            synthetic_csv,
            FeatureSchema.load(schema_path),
            expected_sha256="0" * 64,
        )


def test_inference_allows_omitted_nullable_features(
    synthetic_frame,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)
    row = synthetic_frame.tail(1).drop(
        columns=[
            "feature_market_home_spread",
            "feature_market_total",
            "feature_confidence_score",
        ]
    )

    validated = schema.validate_inference_frame(row)

    assert validated["feature_market_home_spread"].isna().all()
    assert validated["feature_market_total"].isna().all()


def test_inference_rejects_omitted_required_feature(
    synthetic_frame,
    schema_path: Path,
) -> None:
    schema = FeatureSchema.load(schema_path)

    with pytest.raises(SchemaError, match="missing required features"):
        schema.validate_inference_frame(
            synthetic_frame.tail(1).drop(columns=["feature_home_elo"])
        )


def test_v3_schema_models_historical_qb_detail_missingness() -> None:
    schema = FeatureSchema.load(
        Path(__file__).parents[1] / "config" / "feature_schema_v3.yaml"
    )
    qb_details = [
        name
        for name in schema.feature_names
        if name.startswith("feature_qb_form__")
        and name not in {
            "feature_qb_form__applied",
            "feature_qb_form__raw_signal_spread",
        }
    ]

    assert qb_details
    assert all(schema.raw["features"][name]["nullable"] for name in qb_details)
    assert schema.raw["features"]["feature_qb_form__applied"]["nullable"] is False
