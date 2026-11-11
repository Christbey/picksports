import copy
import importlib.util
import unittest
from pathlib import Path

import numpy as np

SPEC = importlib.util.spec_from_file_location("cbb_strength_study", Path(__file__).with_name("cbb_strength_study.py"))
study = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(study)


class CbbStrengthStudyTests(unittest.TestCase):
    def test_sparse_guard_limits_only_the_extreme_part(self):
        self.assertEqual(study.sparse_tail_value(-100, 1, (-60, 20)), -65)
        self.assertEqual(study.sparse_tail_value(60, 4, (-60, 20)), 40)

    def test_sparse_guard_preserves_normal_and_supported_ratings(self):
        self.assertEqual(study.sparse_tail_value(-30, 1, (-60, 20)), -30)
        self.assertEqual(study.sparse_tail_value(-100, 8, (-60, 20)), -100)
        self.assertEqual(study.sparse_tail_value(-100, 1, None), -100)

    def test_minutes_sum_to_200_with_rank_caps(self):
        weights = {player: float(15 - player) for player in range(15)}
        minutes = study.allocate_minutes(weights)
        self.assertAlmostEqual(sum(minutes.values()), 200)
        for player, value in minutes.items():
            self.assertGreaterEqual(value, 0)
            self.assertLessEqual(value, 36 if player < 5 else 24 if player < 9 else 8)

    def test_too_few_members_cannot_silently_create_minutes(self):
        self.assertLess(sum(study.allocate_minutes({player: 30 for player in range(5)}).values()), 200)

    def test_minutes_are_deterministic_without_mutating_weights(self):
        weights = dict.fromkeys(range(12), 0.0)
        before = copy.deepcopy(weights)
        self.assertEqual(study.allocate_minutes(weights), study.allocate_minutes(dict(reversed(list(weights.items())))))
        self.assertEqual(before, weights)

    def test_exposure_counts_dnp_as_zero(self):
        rows = [{"players": {1: 30, 2: 10}}, {"players": {2: 20}}]
        self.assertEqual(study.average_minutes(rows, [1, 2]), {1: 15, 2: 15})

    def test_target_and_same_day_games_are_excluded(self):
        rows = [{"day": "2026-01-01"}, {"day": "2026-01-02"}, {"day": "2026-01-03"}]
        self.assertEqual(study.earlier(rows, "2026-01-02"), rows[:1])

    def test_role_projection_does_not_use_future_members(self):
        rows = [{"id": 1, "day": "2026-01-01", "players": dict.fromkeys(range(10), 20)}, {"id": 2, "day": "2026-01-03", "players": {999: 40}}]
        earlier = study.earlier(rows, "2026-01-02")
        players = {player for row in earlier for player in row["players"]}
        self.assertNotIn(999, study.role_projection(earlier, players))

    def test_naive_database_times_use_chicago(self):
        self.assertEqual(study.timestamp("2026-11-06 12:00:00"), study.timestamp("2026-11-06T18:00:00Z"))

    def test_only_result_revisions_known_by_forecast_are_used(self):
        before = "2026-11-03T12:00:00Z"
        late = "2026-11-05T12:00:00Z"
        dataset = {"games": [[1, "", 2027, 2, 10, 11, 999, 0, "2026-11-03", "", False]], "result_timings": {"1": [{"revision": 1, "home_score": 10, "away_score": 5, "created_at": before, "observed_at": before, "finalized_at": before}, {"revision": 2, "home_score": 20, "away_score": 0, "created_at": late, "observed_at": late, "finalized_at": late}]}}
        target = [2, "", 2027, 2, 10, 11, 5, 1, "2026-11-04", "", False]
        original = copy.deepcopy(dataset)
        result = study.known_results(dataset, target, "2026-11-04T12:00:00Z")
        self.assertEqual(result[0][6:8], [10, 5])
        self.assertEqual(original, dataset)
        self.assertEqual(study.known_results(dataset, target, "2026-11-02T12:00:00Z"), [])

    def test_player_features_shrink_small_samples_more(self):
        league = np.ones(5)
        little = np.array([20, 40, 40, 40, 40, 40])
        large = little * 100
        self.assertTrue(np.all(study.quality_features(little, league) < study.quality_features(large, league)))
        np.testing.assert_allclose(study.quality_features(np.zeros(6), league), 40 * league)

    def test_player_fit_respects_directional_constraints(self):
        rows = [{"features": row.tolist(), "actual": float(target), "baseline": 0} for row, target in zip(np.eye(5), [1, -1, 1, 1, 1])]
        coefficients = study.fit_coefficients(rows, np.ones(5), 1)
        self.assertTrue(np.all(coefficients * study.PLAYER_SIGNS >= 0))
        self.assertEqual(coefficients[3], 0)

    def test_player_adjustment_is_bounded(self):
        self.assertEqual(study.feature_adjustment(np.ones(5) * 100, np.ones(5)), 4)
        self.assertEqual(study.feature_adjustment(np.ones(5) * -100, np.ones(5)), -4)

    def test_home_margin_sign_and_neutral_court(self):
        home = [1, "", 2026, 2, 10, 11, 20, 10, "2025-12-01", "", False]
        neutral = [*home[:10], True]
        self.assertEqual(study.score_edges([home])[10], [(11, 6.5)])
        self.assertEqual(study.score_edges([neutral])[10], [(11, 10)])

    def test_uncertainty_keeps_unchanged_cases_and_is_reproducible(self):
        rows = [{"cohort": "sample", "home_team_id": 1, "away_team_id": 2, "actual": 10, "baseline": 8, "combined": 9}, {"cohort": "sample", "home_team_id": 1, "away_team_id": 3, "actual": 10, "baseline": 8, "combined": 8}]
        result = study.uncertainty(rows, replicates=1000)
        self.assertEqual(result, study.uncertainty(rows, replicates=1000))
        self.assertEqual(result["results"][0]["n"], 2)
        self.assertEqual(result["results"][0]["paired_mae_difference"], -0.5)

    def valid_dataset(self):
        prior = [1, "", 2026, 2, 10, 11, 6, 12, "2025-12-01", "", False]
        target = [2, "", 2027, 2, 10, 11, 6, 12, "2026-11-03", "", False]
        return {
            "schema_version": 1,
            "teams": [{"id": 10}, {"id": 11}],
            "games": [prior, target],
            "cohort_rows": [{"game_id": 2, "cohort": "sample"}],
            "prior_lineups": [{"game": [1, "2025-12-01", 10, 11, 6, 12, False], "sides": {"10": [[player, 33.333, 1, 1, 0, 0, 0, 0] for player in range(1, 7)], "11": [[player, 33.333, 2, 1, 0, 0, 0, 0] for player in range(7, 13)]}}],
            "rosters": {"10": list(range(1, 7)), "11": list(range(7, 13))},
        }

    def test_validated_player_boxes_are_accepted(self):
        study.validate_dataset(self.valid_dataset())

    def test_duplicate_targets_are_rejected(self):
        data = self.valid_dataset()
        data["cohort_rows"] *= 2
        with self.assertRaisesRegex(ValueError, "Duplicate target"):
            study.validate_dataset(data)

    def test_wrong_season_or_changed_result_is_rejected(self):
        data = self.valid_dataset()
        data["games"][0][2] = 2027
        with self.assertRaisesRegex(ValueError, "prior-season"):
            study.validate_dataset(data)
        data = self.valid_dataset()
        data["prior_lineups"][0]["game"][4] = 7
        with self.assertRaisesRegex(ValueError, "disagree"):
            study.validate_dataset(data)

    def test_incomplete_player_boxes_are_rejected(self):
        data = self.valid_dataset()
        data["prior_lineups"][0]["sides"]["10"].pop()
        with self.assertRaisesRegex(ValueError, "reconcile"):
            study.validate_dataset(data)

    def test_duplicate_roster_members_are_rejected(self):
        data = self.valid_dataset()
        data["rosters"]["10"] = [1, 1, 2, 3, 4, 5]
        with self.assertRaisesRegex(ValueError, "roster membership"):
            study.validate_dataset(data)

    def test_summary_retains_ineligible_games(self):
        rows = [{"cohort": "sample", "player_eligible": False, "actual": 10, "baseline": 8, "player": 8, "sparse": 9, "combined": 9}, {"cohort": "sample", "player_eligible": True, "actual": 10, "baseline": 8, "player": 10, "sparse": 8, "combined": 10}]
        summary = study.summarize(rows)
        self.assertEqual(summary[0]["n"], 2)
        self.assertEqual(summary[0]["baseline"], 2)
        self.assertEqual(summary[0]["player"], 1)


if __name__ == "__main__":
    unittest.main()
