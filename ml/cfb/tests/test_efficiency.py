import copy
import hashlib
import json
import tempfile
from unittest.mock import patch
import math
import sys
import unittest
from pathlib import Path
sys.path.insert(0, str(Path(__file__).parents[1]))
from scoring import regulation_scores, recency_candidates, week, train, evaluate, fit, simulate, summary, predict, tilt, select_blend
from efficiency import POLICY, drive_performance, pace_features, stadium_features, contextual_features, postgame_expectancy
from test_scoring import games


def observed_games():
    rows = games()
    for i, row in enumerate(rows):
        row["kickoff"] = row["kickoff"].replace("T00:00:00", "T12:00:00")
        row["venue"] = "A" if i%2 else "B"
        row["context"] = {"home": {"qb_changed": i%2, "out_records": i%3}, "away": {"qb_changed": 0}}
        for j, drive in enumerate(row["drives"]):
            drive["play_observations"] = [
                {"kind": "pass", "down": 1, "distance": 10, "yards_to_endzone": 75, "yards": 5+(j%3)*10,
                 "epa": .2 if j%3 else -.1, "sack": False, "period": drive["period"]},
                {"kind": "rush", "down": 2, "distance": 5, "yards_to_endzone": 70, "yards": 2+j%4,
                 "epa": .05, "period": drive["period"]}]
    return rows


class EfficiencyTests(unittest.TestCase):
    def test_regulation_selection_does_not_require_unobserved_deep_overtime(self):
        rows=observed_games()
        model=fit(rows,"2024-08-01T00:00:00+00:00")
        model["overtime"]=[]
        row={**rows[0],"game_id":999,"kickoff":"2024-09-01T00:00:00+00:00"}
        report=evaluate(model,[row],draws=100,regulation_only=True)
        self.assertEqual(len(report["games"]),1)
        self.assertEqual(report["target"],"regulation_scores")
        self.assertEqual(report["games"][0]["actual_total"],sum(regulation_scores(row).values()))
        self.assertEqual(report["games"][0]["priced_markets"],[])
        self.assertEqual(summary([{"home":14,"away":14,"probability":1}])["home_win"],.5)

    def test_season_decay_is_not_selected_from_an_uninformative_single_season(self):
        rows = observed_games()
        self.assertEqual(recency_candidates(rows, "2024-08-01T00:00:00+00:00"), (1.,))
        earlier = [{**row, "season": 2023, "kickoff": row["kickoff"].replace("2024", "2023"),
                    "source_available_at": row["source_available_at"].replace("2024", "2023")} for row in rows]
        self.assertEqual(recency_candidates(rows+earlier, "2024-08-01T00:00:00+00:00"), (.5, .8, 1.))

    def test_kickoff_week_respects_the_explicit_utc_holdout_boundaries(self):
        self.assertEqual(week({"kickoff": "2026-10-11T20:00:00-05:00"}), "2026-10-12")
        self.assertEqual(week({"kickoff": "2026-10-11T18:00:00-05:00"}), "2026-10-05")

    def test_success_thresholds_and_explosive_definitions(self):
        plays = [{"kind": "pass", "down": 1, "distance": 10, "yards": 5},
                 {"kind": "rush", "down": 2, "distance": 10, "yards": 7},
                 {"kind": "pass", "down": 3, "distance": 10, "yards": 9},
                 {"kind": "pass", "down": 4, "distance": 10, "yards": 20}]
        x = drive_performance({"play_observations": plays})
        self.assertEqual(x["success_rate"], .75)
        self.assertEqual(x["explosive_rate"], .25)
        self.assertIsNone(drive_performance({"play_observations": [{"kind":"pass","down":1,"distance":0,"yards":math.nan}]}))

    def test_fit_and_inference_use_efficiency_context_and_opponent_pace(self):
        model = fit(observed_games(), "2024-08-01T00:00:00+00:00")
        self.assertEqual(model["feature_policy"], POLICY)
        self.assertTrue({"pass_epa", "rush_epa", "pass_success", "rush_success", "pass_explosive", "rush_explosive"}.issubset(model["efficiency_keys"]))
        self.assertIn("opponent:2", model["models"]["pace"]["coefficients"])
        self.assertIn("venue:A:home", model["models"]["drive"]["coefficients"])
        self.assertIn("context:qb_changed", model["models"]["drive"]["coefficients"])
        self.assertNotIn("postgame_performance", model["efficiency_keys"])
        self.assertEqual(stadium_features({"venue":"A", "neutral":True}, "home"), {})
        self.assertEqual(contextual_features({},"home")["context:out_records:missing"], 1.)

    def test_postgame_ignores_target_final_score_epa_and_direct_turnovers(self):
        model = fit(observed_games(), "2024-08-01T00:00:00+00:00")
        row = copy.deepcopy(observed_games()[0])
        row.update(game_id=999, kickoff="2024-09-01T00:00:00+00:00")
        first = postgame_expectancy(model, row, predict, tilt)
        row.update(home_score=0, away_score=99)
        for drive in row["drives"]:
            drive["offense_points"] = 99
            drive["opponent_points"] = 99
            for play in drive["play_observations"]:
                play["epa"] = 999
                play["turnover"] = True
        self.assertEqual(first, postgame_expectancy(model, row, predict, tilt))
        self.assertGreaterEqual(first["home_win_expectancy"], 0.)
        self.assertLessEqual(first["home_win_expectancy"], 1.)

    def test_postgame_rejects_training_targets_and_incomplete_play_evidence(self):
        model = fit(observed_games(), "2024-08-01T00:00:00+00:00")
        row = copy.deepcopy(observed_games()[0])
        row["kickoff"] = "2024-09-01T00:00:00+00:00"
        with self.assertRaisesRegex(ValueError, "exclude target"):
            postgame_expectancy(model, row, predict, tilt)
        row["game_id"] = 999
        row["drives"][0]["play_observations"] = []
        with self.assertRaisesRegex(ValueError, "Incomplete"):
            postgame_expectancy(model, row, predict, tilt)

    def test_blend_selection_is_supported_only_on_paired_earlier_games(self):
        model = fit(games(), "2024-08-01T00:00:00+00:00")
        result = select_blend(model, [])
        self.assertEqual(result["weight"], 0.)
        self.assertEqual(result["status"], "insufficient_common_fpi_validation")


    def test_real_training_path_freezes_separate_periods_and_remains_a_challenger(self):
        rows = []
        for row in observed_games():
            for repetition in range(16):
                rows.append({**row, "game_id": row["game_id"]*100+repetition})
        with tempfile.TemporaryDirectory() as directory:
            data = Path(directory)/"games.jsonl"
            data.write_text("".join(json.dumps(row)+"\n" for row in rows))
            manifest = Path(directory)/"manifest.json"
            manifest.write_text(json.dumps({"data_path": str(data), "sha256": hashlib.sha256(data.read_bytes()).hexdigest(),
                "as_of": "2024-08-01T00:00:00+00:00", "test_from": "2024-05-20T00:00:00+00:00",
                "test_until": "2024-06-17T00:00:00+00:00", "selection_from":"2024-03-25T00:00:00Z",
                "calibration_from":"2024-04-22T00:00:00Z", "feature_policy": POLICY, "signal_families": {}}))
            output = Path(directory)/"model.json"
            def smaller_evaluation(model, group, draws=2000, benchmark=False, regulation_only=False):
                return evaluate(model, group, draws=100, benchmark=benchmark, regulation_only=regulation_only)
            with patch("scoring.evaluate", side_effect=smaller_evaluation):
                result = train(manifest, output)
            model = json.loads(output.read_text())
            self.assertEqual(result["status"], "challenger")
            self.assertFalse(model["promotion_allowed"])
            self.assertEqual(model["feature_policy"], POLICY)
            evaluation = json.loads(Path(str(output)+".evaluation.json").read_text())
            self.assertEqual(evaluation["status"], "research_evaluation_only")
            self.assertFalse(set(evaluation["training_ids"]) & {game["game_id"] for game in model["holdout"]["games"]})
            self.assertLess(model["split"]["validation_week"], model["split"]["calibration_week"])
            self.assertLess(model["split"]["calibration_week"], model["split"]["test_week"])
            self.assertTrue(all("2024-05-20" <= game["week"] < "2024-06-17" for game in model["holdout"]["games"]))
            invalid = json.loads(manifest.read_text())
            invalid["calibration_from"] = "2024-06-03T00:00:00Z"
            manifest.write_text(json.dumps(invalid))
            with self.assertRaisesRegex(ValueError, "chronological and disjoint"):
                train(manifest, Path(directory)/"overlap.json")
            invalid["calibration_from"] = "2024-04-22T00:00:00Z"
            invalid["test_until"] = "2025-06-16T00:00:00+00:00"
            manifest.write_text(json.dumps(invalid))
            with self.assertRaisesRegex(ValueError, "completed Monday"):
                train(manifest, Path(directory)/"invalid.json")


if __name__ == "__main__":
    unittest.main()
