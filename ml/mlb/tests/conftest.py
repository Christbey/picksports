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
    config = yaml.safe_load(source.read_text(encoding="utf-8"))
    config["training"]["splits"].update(
        {
            "minimum_training_rows": 80,
            "final_calibration_weeks": 2,
            "final_test_weeks": 2,
            "rolling_initial_training_weeks": 8,
            "rolling_calibration_weeks": 2,
            "rolling_training_weeks": 20,
            "maximum_rolling_windows": 2,
        }
    )
    config["training"]["xgboost_classifier"]["n_estimators"] = 8
    config["training"]["xgboost_regressor"]["n_estimators"] = 8
    destination = tmp_path / "feature_schema.yaml"
    destination.write_text(
        yaml.safe_dump(config, sort_keys=False), encoding="utf-8"
    )
    return destination


@pytest.fixture
def synthetic_frame() -> pd.DataFrame:
    rng = np.random.default_rng(20260729)
    rows: list[dict[str, object]] = []
    game_id = 1
    start = pd.Timestamp("2025-04-01T17:00:00Z")
    for week in range(32):
        season = 2025 if week < 16 else 2026
        for game in range(10):
            team_edge = float((game - 4.5) * 18 + rng.normal(0, 12))
            pitcher_edge = float(((game % 5) - 2) * 22 + rng.normal(0, 10))
            latent_margin = team_edge / 55 + pitcher_edge / 65 + rng.normal(0, 1.8)
            home_win = int(latent_margin > 0)
            home_margin = float(
                max(1, round(abs(latent_margin)))
                if home_win
                else -max(1, round(abs(latent_margin)))
            )
            expected_total = float(8.6 + rng.normal(0, 0.8))
            total = float(max(2, round(expected_total + rng.normal(0, 2.1))))
            game_start = start + pd.Timedelta(weeks=week, hours=game * 2)
            probability = float(1 / (1 + np.exp(-latent_margin / 2.2)))
            market_probability = (
                float(np.clip(probability + rng.normal(0, 0.04), 0.05, 0.95))
                if game % 3
                else np.nan
            )
            rows.append(
                {
                    "snapshot_run_id": f"snapshot-{game_id}",
                    "model_run_id": "laravel-export-run",
                    "config_hash": _hash("config"),
                    "code_version": "test-code",
                    "game_id": game_id,
                    "season": season,
                    "game_start_at": game_start.isoformat(),
                    "features_available_at": (
                        game_start - pd.Timedelta(hours=3)
                    ).isoformat(),
                    "pregame_safe": 1,
                    "availability_status": (
                        "verified_reconstruction"
                        if season == 2025
                        else "observed_pregame"
                    ),
                    "feature_version": "mlb-core-v1",
                    "feature_hash": _hash(f"features-{game_id}"),
                    "target_hash": _hash(f"targets-{game_id}"),
                    "feature_home_team_elo": 1500 + team_edge / 2,
                    "feature_away_team_elo": 1500 - team_edge / 2,
                    "feature_home_pitcher_elo": 1500 + pitcher_edge / 2,
                    "feature_away_pitcher_elo": 1500 - pitcher_edge / 2,
                    "feature_home_combined_elo": 1500 + (team_edge + pitcher_edge) / 4,
                    "feature_away_combined_elo": 1500 - (team_edge + pitcher_edge) / 4,
                    "feature_home_pitcher_confidence": 1.0,
                    "feature_away_pitcher_confidence": 0.9,
                    "feature_season_sample_games": float(week * 10),
                    "feature_season_progress_scale": min(1.0, week / 16),
                    "feature_team_weight": 0.6,
                    "feature_pitcher_weight": 0.4,
                    "feature_model_win_probability": probability,
                    "feature_model_predicted_margin": latent_margin,
                    "feature_model_predicted_total": expected_total,
                    "feature_market_home_win_probability": market_probability,
                    "feature_market_home_spread": round(latent_margin * 2) / 2,
                    "feature_market_total": round(expected_total * 2) / 2,
                    "target_home_win": home_win,
                    "target_home_margin": home_margin,
                    "target_total_points": total,
                }
            )
            game_id += 1
    return pd.DataFrame(rows)


@pytest.fixture
def synthetic_csv(tmp_path: Path, synthetic_frame: pd.DataFrame) -> Path:
    path = tmp_path / "mlb.csv"
    synthetic_frame.to_csv(path, index=False)
    return path


def _hash(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()
