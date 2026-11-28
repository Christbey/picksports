import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).parents[1]))
from scoring import source_provenance, train


class SourceProvenanceTests(unittest.TestCase):
    def test_incomplete_manifests_are_refused_before_data_or_training(self):
        for scan in [{"complete": False, "has_more": True}, {"complete": True, "after_game_id": 5}, "invalid"]:
            with self.subTest(scan=scan), tempfile.TemporaryDirectory() as directory:
                manifest = Path(directory) / "manifest.json"
                manifest.write_text(json.dumps({"scan": scan, "data_path": "never-open-missing-data"}))
                output = Path(directory) / "never-created.json"
                with self.assertRaisesRegex(ValueError, "complete export"):
                    train(manifest, output)
                self.assertFalse(output.exists())

    def test_retains_exact_exported_receipts_only_for_selected_training_ids(self):
        proof = {"boxscore": {"check_id": 4, "receipt_id": 10, "context_hash": "a" * 64, "source_hash": "b" * 64, "game_feature_hash": "d" * 64},
                 "plays": {"check_id": 5, "receipt_id": 11, "context_hash": "a" * 64, "source_hash": "c" * 64, "game_feature_hash": "d" * 64}}
        rows = [{"game_id": 1, "source_assessments": proof}, {"game_id": 2, "source_assessments": {"not": "selected"}}]
        result = source_provenance(rows, [1])
        self.assertEqual(result, {"policy": "cfb-observed-assessment-v1", "games": {"1": proof}})

    def test_unknown_legacy_source_provenance_is_not_manufactured(self):
        self.assertEqual(source_provenance([{"game_id": 1}], [1])["games"], {"1": None})
