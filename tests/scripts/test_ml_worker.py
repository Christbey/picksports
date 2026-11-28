import ast
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch
from uuid import uuid4

ROOT = Path(__file__).resolve().parents[2]
spec = importlib.util.spec_from_file_location("ml_worker", ROOT / "scripts/ml_worker.py")
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WorkerTests(unittest.TestCase):
    def test_source_identity_matches_existing_pipelines(self):
        for sport in ("nfl", "mlb"):
            pipeline = ROOT / f"ml/{sport}/src/picksports_{sport}_ml/pipeline.py"
            function = next(node for node in ast.parse(pipeline.read_text()).body if isinstance(node, ast.FunctionDef) and node.name == "_source_hash")
            scope = {"Path": Path, "hashlib": hashlib, "__file__": str(pipeline)}
            exec(compile(ast.Module(body=[function], type_ignores=[]), str(pipeline), "exec"), scope)
            self.assertEqual(scope["_source_hash"](), worker.source_hash(ROOT / "ml" / sport, sport))

    def test_rejects_http_and_credential_bearing_origins(self):
        with tempfile.TemporaryDirectory() as directory:
            for url in ("http://example.com", "https://user:secret@example.com", "https://example.com/elsewhere", "https://example.com?token=secret"):
                with self.assertRaises(worker.WorkerError):
                    worker.Worker(url, "token", ROOT, Path(directory))

    def test_token_file_requires_private_permissions(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "credential"
            path.write_text("a" * 96)
            os.chmod(path, 0o600)
            self.assertEqual(worker.private_token(path), "a" * 96)
            os.chmod(path, 0o644)
            with self.assertRaises(worker.WorkerError):
                worker.private_token(path)

    def test_download_rejects_corruption_and_removes_partial_file(self):
        with tempfile.TemporaryDirectory() as directory:
            client = worker.Worker("https://example.com", "token", ROOT, Path(directory))
            client.request = lambda *args, **kwargs: io.BytesIO(b"corrupt")
            target = Path(directory) / "dataset.csv"
            with self.assertRaises(worker.WorkerError):
                client.download("/input", "owner", {"bytes": 7, "sha256": "a" * 64}, target)
            self.assertFalse(target.exists())
            self.assertFalse(target.with_suffix(".download").exists())

    def test_no_redirect_can_forward_credentials(self):
        with self.assertRaises(worker.WorkerError):
            worker.NoRedirect().redirect_request(None, None, 302, "redirect", {}, "https://untrusted.example")

    def test_rejects_artifact_traversal_and_symlinks(self):
        for path in ("../secret", "/absolute", "models/../secret", "models\\secret", ".secret"):
            self.assertFalse(worker.safe_artifact(path))
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / "original").write_text("value")
            (root / "link").symlink_to(root / "original")
            with self.assertRaises(worker.WorkerError):
                worker.inventory(root)

    def test_streams_uploads_instead_of_loading_full_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / "model.ubj"
            path.write_bytes(b"x" * (2 * 1024 * 1024 + 3))
            client = worker.Worker("https://example.com", "token", ROOT, root)
            received = []

            def request(method, route, headers, data):
                self.assertEqual(method, "PUT")
                self.assertEqual(headers["Content-Length"], str(path.stat().st_size))
                for chunk in data:
                    self.assertLessEqual(len(chunk), 1024 * 1024)
                    received.append(len(chunk))
                return io.BytesIO(b"{}")

            client.request = request
            client.upload("/files", "owner", root, (path.name, worker.file_hash(path), path.stat().st_size))
            self.assertEqual(sum(received), path.stat().st_size)

    def test_worker_uses_python_without_application_or_worker_secrets_and_retries_transfer(self):
        with tempfile.TemporaryDirectory() as directory:
            client = worker.Worker("https://example.com", "worker-secret", ROOT, Path(directory))
            cycle, run, owner = str(uuid4()), str(uuid4()), str(uuid4())
            task = {"cycle_id": cycle, "run_id": run, "owner_token": owner, "attempt_id": 1, "sport": "nfl",
                    "source_hash": worker.source_hash(ROOT / "ml/nfl", "nfl"),
                    "dataset": {"sha256": "a" * 64, "bytes": 1}, "schema": {"sha256": "b" * 64, "bytes": 1},
                    "options": {"threads": 2, "timeout_seconds": 14400, "tune": False, "explain": False}}
            client.json_request = lambda method, route, headers=None, **kwargs: task if route.endswith("claim") else {"status": "completed"}
            client.download = lambda route, owner, descriptor, target: target.write_text("input")
            uploads = []
            client.upload = lambda *args: uploads.append(args[-1][0])

            def train(command, **kwargs):
                self.assertEqual(command[1:4], ["-m", "picksports_nfl_ml", "train"])
                self.assertNotIn("worker-secret", command)
                self.assertNotIn("DB_PASSWORD", kwargs["env"])
                self.assertNotIn("AWS_SECRET_ACCESS_KEY", kwargs["env"])
                self.assertNotIn("ML_WORKER_TOKEN", kwargs["env"])
                artifacts = Path(directory) / run / "artifacts" / run
                artifacts.mkdir(parents=True)
                (artifacts / "manifest.json").write_text("{}")
                (artifacts / "model.ubj").write_text("model")
                process = unittest.mock.MagicMock()
                process.__enter__.return_value = process
                process.pid = None
                process.wait.return_value = 0
                return process

            with patch.dict(os.environ, {"DB_PASSWORD": "db-secret", "AWS_SECRET_ACCESS_KEY": "storage-secret", "ML_WORKER_TOKEN": "worker-secret"}), patch.object(subprocess, "Popen", side_effect=train) as process:
                self.assertEqual(client.run(cycle), {"status": "completed"})
                self.assertEqual(client.run(cycle), {"status": "completed"})
                self.assertEqual(process.call_count, 1)
            self.assertEqual(uploads, ["manifest.json", "model.ubj", "manifest.json", "model.ubj"])

    def test_rejects_excess_artifact_count_before_hashing_any_file(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for number in range(65):
                (root / f"model-{number}.ubj").write_bytes(b"x")
            with patch.object(worker.hashlib, "sha256", side_effect=AssertionError("No hashing before inventory admission")) as digest:
                with self.assertRaises(worker.WorkerError):
                    worker.inventory(root)
                digest.assert_not_called()

    def test_rejects_oversized_artifact_before_hashing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with (root / "model.ubj").open("wb") as handle:
                handle.truncate(256 * 1024**2 + 1)  # Sparse synthetic metadata, not an allocated training artifact.
            with patch.object(worker.hashlib, "sha256", side_effect=AssertionError("No hashing before size admission")) as digest:
                with self.assertRaises(worker.WorkerError):
                    worker.inventory(root)
                digest.assert_not_called()

    def test_rejects_aggregate_artifact_size_before_hashing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for number in range(5):
                with (root / f"model-{number}.ubj").open("wb") as handle:
                    handle.truncate(225 * 1024**2)
            with patch.object(worker.hashlib, "sha256", side_effect=AssertionError("No hashing before total admission")) as digest:
                with self.assertRaises(worker.WorkerError):
                    worker.inventory(root)
                digest.assert_not_called()

    def test_valid_inventory_retains_sorted_hash_and_size_contract(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for number in reversed(range(64)):
                (root / f"model-{number:02}.ubj").write_bytes(b"model")
            with patch.object(worker, "file_hash", wraps=worker.file_hash) as digest:
                files = worker.inventory(root)
            self.assertEqual(len(files), 64)
            self.assertEqual(digest.call_count, 64)
            self.assertEqual(files, [(f"model-{number:02}.ubj", hashlib.sha256(b"model").hexdigest(), 5) for number in range(64)])

    def test_filesystem_entry_admission_stops_a_large_directory_walk(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for number in range(1000):
                (root / f"empty-{number}").mkdir()
            visited = []
            original = Path.rglob

            def counting_walk(path, pattern):
                for entry in original(path, pattern):
                    visited.append(entry)
                    yield entry

            with patch.object(Path, "rglob", counting_walk):
                with self.assertRaises(worker.WorkerError):
                    worker.inventory(root)
            self.assertLessEqual(len(visited), 513)

    def test_inventory_rejects_a_file_that_grows_after_size_admission(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            path = root / "model.ubj"
            path.write_bytes(b"x")
            original = worker.file_hash

            def changed_file(target, **kwargs):
                target.write_bytes(b"changed")
                return original(target, **kwargs)

            with patch.object(worker, "file_hash", side_effect=changed_file):
                with self.assertRaises(worker.WorkerError):
                    worker.inventory(root)

    def test_hash_reads_at_most_expected_size_plus_one_byte(self):
        counts = []

        class CountingStream(io.BytesIO):
            def read(self, count=-1):
                counts.append(count)
                return super().read(count)

        with patch.object(Path, "open", return_value=CountingStream(b"x" * 10000)):
            with self.assertRaises(worker.WorkerError):
                worker.file_hash(Path("synthetic-model.ubj"), expected_bytes=3)
        self.assertEqual(counts, [4])

    def test_inventory_refuses_a_symlink_root(self):
        with tempfile.TemporaryDirectory() as directory:
            parent = Path(directory)
            target = parent / "target"
            target.mkdir()
            (target / "model.ubj").write_bytes(b"model")
            alias = parent / "alias"
            alias.symlink_to(target, target_is_directory=True)
            with self.assertRaises(worker.WorkerError):
                worker.inventory(alias)

    def test_reporting_redacts_credentials_and_cannot_break_training(self):
        with tempfile.TemporaryDirectory() as directory:
            client = worker.Worker("https://example.com", "a" * 96, ROOT, Path(directory))
            reporter = worker.Reporter(client)
            owner = str(uuid4())
            reporter.task = {"cycle_id": str(uuid4()), "attempt_id": 1, "owner_token": owner}
            reporter.log = Path(directory) / "training.log"
            reporter.log.write_text("output " + client.token + " " + owner)
            sent = []
            client.json_request = lambda *args, **kwargs: sent.append((args, kwargs))
            reporter.send()
            progress = sent[-1][1]["payload"]
            self.assertEqual(progress["sequence"], 1)
            self.assertNotIn(client.token, progress["log_tail"])
            self.assertNotIn(owner, progress["log_tail"])
            self.assertIn("[redacted]", progress["log_tail"])
            client.json_request = unittest.mock.Mock(side_effect=worker.WorkerError("unavailable"))
            reporter.send()  # Best-effort telemetry failure does not raise.
            self.assertFalse(reporter.sending.locked())


if __name__ == "__main__":
    unittest.main()
