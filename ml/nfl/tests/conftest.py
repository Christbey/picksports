from __future__ import annotations

import hashlib
from pathlib import Path

import numpy as np
import pandas as pd
import pytest
import yaml


@pytest.fixture
def schema_path(tmp_path: Path) -> Path:
    source = Path(__file__).parents[1] / "config" / "feature_schema.yaml"
    with source.open("r", encoding="utf-8") as handle:
        config = yaml.safe_load(handle)
    config["training"]["minimum_training_seasons"] = 3
    config["training"]["xgboost_classifier"]["n_estimators"] = 8
    config["training"]["xgboost_regressor"]["n_estimators"] = 8
    config["training"]["tuning"]["enabled"] = False
    config["training"]["tuning"]["trials_per_model"] = 1
    config["training"]["explanations"]["enabled"] = False
    config["training"]["explanations"]["max_rows"] = 8
    destination = tmp_path / "feature_schema.yaml"
    with destination.open("w", encoding="utf-8") as handle:
        yaml.safe_dump(config, handle, sort_keys=False)
    return destination


@pytest.fixture
def synthetic_frame() -> pd.DataFrame:
    rng = np.random.default_rng(20260726)
    rows: list[dict[str, object]] = []
    game_id = 1
    for season in range(2018, 2025):
        for week in range(1, 17):
            elo_diff = float(rng.normal(0, 115))
            home_advantage = 25.0
            latent_margin = elo_diff / 30 + home_advantage / 12 + rng.normal(0, 7)
            home_win = int(latent_margin > 0)
            home_margin = (
                float(max(1, round(abs(latent_margin))))
                if home_win
                else float(-max(1, round(abs(latent_margin))))
            )
            expected_total = 43 + rng.normal(0, 4)
            total_points = float(max(10, round(expected_total + rng.normal(0, 8))))
            start = pd.Timestamp(
                year=season,
                month=9 + (week - 1) // 8,
                day=1 + ((week - 1) % 8) * 3,
                hour=18,
                tz="UTC",
            )
            feature_values = {
                "feature_home_elo": 1500 + elo_diff / 2,
                "feature_away_elo": 1500 - elo_diff / 2,
                "feature_elo_diff": elo_diff,
                "feature_adjusted_home_elo": 1500 + elo_diff / 2 + home_advantage,
                "feature_home_field_advantage": home_advantage,
                "feature_week": float(week),
                "feature_season_type": 2.0,
                "feature_neutral_site": 0.0,
                "feature_model_predicted_spread": latent_margin + rng.normal(0, 2),
                "feature_model_predicted_total": expected_total,
                "feature_model_win_probability": float(
                    1 / (1 + np.exp(-(elo_diff + home_advantage) / 180))
                ),
                "feature_confidence_score": float(55 + abs(elo_diff) / 30),
                "feature_market_home_spread": float(round(latent_margin * 2) / 2),
                "feature_market_total": float(round(expected_total * 2) / 2),
            }
            rows.append(
                {
                    "snapshot_run_id": f"snapshot-{game_id}",
                    "model_run_id": "export-run",
                    "config_hash": "config-hash",
                    "code_version": "code-version",
                    "game_id": game_id,
                    "season": season,
                    "game_start_at": start.isoformat(),
                    "features_available_at": (start - pd.Timedelta(hours=4)).isoformat(),
                    "pregame_safe": 1,
                    "availability_status": "verified_reconstruction",
                    "feature_hash": _hash(f"features-{game_id}"),
                    "target_hash": _hash(f"target-{game_id}"),
                    **feature_values,
                    "target_home_win": home_win,
                    "target_home_margin": home_margin,
                    "target_total_points": total_points,
                }
            )
            game_id += 1
    return pd.DataFrame(rows)


@pytest.fixture
def synthetic_csv(tmp_path: Path, synthetic_frame: pd.DataFrame) -> Path:
    path = tmp_path / "nfl.csv"
    synthetic_frame.to_csv(path, index=False)
    return path


def _hash(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()
