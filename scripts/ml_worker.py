#!/usr/bin/env python3
"""One-shot, database-free PickSports training worker. Uses only the standard library."""
from __future__ import annotations

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import threading
import urllib.error
import urllib.request
from urllib.parse import urlsplit
from uuid import UUID


class WorkerError(RuntimeError):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        raise WorkerError("Worker API redirects are refused")


def file_hash(path: Path, *, expected_bytes: int | None = None) -> str:
    digest = hashlib.sha256()
    size = 0
    with path.open("rb") as handle:
        while True:
            count = 1024 * 1024 if expected_bytes is None else min(1024 * 1024, max(1, expected_bytes - size + 1))
            chunk = handle.read(count)
            if not chunk:
                break
            size += len(chunk)
            if expected_bytes is not None and size > expected_bytes:
                raise WorkerError("Artifact changed after inventory admission")
            digest.update(chunk)
    if expected_bytes is not None and size != expected_bytes:
        raise WorkerError("Artifact changed after inventory admission")
    return digest.hexdigest()


def source_hash(root: Path, sport: str) -> str:
    paths = [root / "Dockerfile", root / "pyproject.toml", root / "requirements.lock.txt"]
    if sport == "mlb":
        paths += sorted((root / "config").rglob("*.yaml"))
    paths += sorted((root / "src").rglob("*.py"))
    digest = hashlib.sha256()
    for path in paths:
        digest.update(path.relative_to(root).as_posix().encode() + b"\0")
        with path.open("rb") as handle:
            for chunk in iter(lambda: handle.read(1024 * 1024), b""):
                digest.update(chunk)
    return digest.hexdigest()


def private_directory(path: Path) -> None:
    if path.is_symlink():
        raise WorkerError("Worker directory must not be a symlink")
    path.mkdir(parents=True, mode=0o700, exist_ok=True)
    info = path.stat()
    if info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) & 0o077:
        raise WorkerError("Worker directory must be owned by this user and private (0700)")


def private_token(path: Path) -> str:
    if path.is_symlink():
        raise WorkerError("Token file must not be a symlink")
    info = path.stat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or stat.S_IMODE(info.st_mode) & 0o077:
        raise WorkerError("Token file must be owned by this user and private (0600)")
    token = path.read_text().strip()
    if re.fullmatch(r"[a-f0-9]{96}", token) is None:
        raise WorkerError("Invalid worker credential file")
    return token


def safe_artifact(name: str) -> bool:
    return len(name) <= 191 and re.fullmatch(
        r"(?:[A-Za-z0-9_-][A-Za-z0-9_.-]*|(?:models|calibrators|explanations|tuning)/[A-Za-z0-9_-][A-Za-z0-9_.-]*)", name
    ) is not None


def inventory(root: Path) -> list[tuple[str, str, int]]:
    if root.is_symlink() or not root.is_dir():
        raise WorkerError("Artifact root must be an existing directory without a symlink")
    metadata = []
    total = 0
    # Admit the entire bounded metadata inventory before hashing any artifact.
    # Valid files use at most one of four supported subdirectories (68 entries at most).
    for visited, path in enumerate(root.rglob("*"), start=1):
        if visited > 512:
            raise WorkerError("Artifact inventory exceeds filesystem entry limit")
        info = path.lstat()
        if stat.S_ISLNK(info.st_mode):
            raise WorkerError("Symlinks are not valid training artifacts")
        if stat.S_ISDIR(info.st_mode):
            continue
        if not stat.S_ISREG(info.st_mode):
            raise WorkerError("Only regular training artifact files are supported")
        relative = path.relative_to(root).as_posix()
        if not safe_artifact(relative):
            raise WorkerError("Invalid artifact path")
        if len(metadata) >= 64:
            raise WorkerError("Artifact inventory exceeds transfer limits")
        size = info.st_size
        if size > 256 * 1024**2:
            raise WorkerError("Artifact exceeds transfer file limit")
        total += size
        if total > 1024**3:
            raise WorkerError("Artifact inventory exceeds transfer limits")
        metadata.append((relative, path, size))
    if not metadata:
        raise WorkerError("Artifact inventory exceeds transfer limits")
    return [(name, file_hash(path, expected_bytes=size), size) for name, path, size in sorted(metadata)]


def result_hash(files: list[tuple[str, str, int]]) -> str:
    digest = hashlib.sha256()
    for name, checksum, size in sorted(files):
        digest.update(f"{name}\0{checksum}\0{size}\n".encode())
    return digest.hexdigest()


class Worker:
    def __init__(self, url: str, token: str, repo: Path, workdir: Path):
        parsed = urlsplit(url)
        if parsed.scheme != "https" or not parsed.netloc or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path not in ("", "/"):
            raise WorkerError("Worker API URL must be an HTTPS origin without credentials or a path")
        self.base = url.rstrip("/") + "/api/ml-worker/v1"
        self.token, self.repo, self.workdir = token, repo.resolve(), workdir.expanduser().absolute()
        self.opener = urllib.request.build_opener(NoRedirect())
        private_directory(self.workdir)

    def request(self, method: str, route: str, headers=None, data=None, timeout=300):
        request = urllib.request.Request(self.base + route, method=method, data=data, headers={
            "Accept": "application/json", "Authorization": "Bearer " + self.token, **(headers or {}),
        })
        try:
            return self.opener.open(request, timeout=timeout)
        except urllib.error.HTTPError as error:
            raise WorkerError(f"Worker API refused request (HTTP {error.code})") from None
        except urllib.error.URLError:
            raise WorkerError("Worker API connection failed; rerun to recover the attempt") from None

    def json_request(self, method: str, route: str, headers=None, payload=None, timeout=300):
        data = json.dumps(payload).encode() if payload is not None else (b"" if method == "POST" else None)
        headers = {**(headers or {}), **({"Content-Type": "application/json"} if payload is not None else {})}
        with self.request(method, route, headers=headers, data=data, timeout=timeout) as response:
            return json.load(response)

    def download(self, route: str, owner: str, descriptor: dict, destination: Path):
        if destination.is_file() and not destination.is_symlink() and file_hash(destination) == descriptor["sha256"]:
            return
        temporary = destination.with_suffix(".download")
        temporary.unlink(missing_ok=True)
        try:
            with self.request("GET", route, headers={"X-Training-Owner": owner}) as response, temporary.open("xb") as output:
                os.chmod(temporary, 0o600)
                size = 0
                while chunk := response.read(1024 * 1024):
                    size += len(chunk)
                    if size > descriptor["bytes"]:
                        raise WorkerError("Downloaded input exceeds its descriptor")
                    output.write(chunk)
            if size != descriptor["bytes"] or file_hash(temporary) != descriptor["sha256"]:
                raise WorkerError("Downloaded input failed integrity verification")
            temporary.replace(destination)
        finally:
            temporary.unlink(missing_ok=True)

    def upload(self, route: str, owner: str, root: Path, entry: tuple[str, str, int]):
        name, checksum, size = entry
        with (root / name).open("rb") as handle:
            chunks = iter(lambda: handle.read(1024 * 1024), b"")
            with self.request("PUT", route, headers={
                "X-Training-Owner": owner, "X-Artifact-Path": name, "X-Content-SHA256": checksum,
                "Content-Type": "application/octet-stream", "Content-Length": str(size),
            }, data=chunks) as response:
                json.load(response)

    def run(self, cycle: str | None = None):
        self.reporter = Reporter(self)
        self.reporter.start()
        try:
            return self._run(cycle)
        finally:
            self.reporter.stop()

    def _run(self, cycle: str | None = None):
        if cycle is None:
            tasks = self.json_request("GET", "/cycles")["data"]
            if not tasks:
                return {"status": "idle"}
            cycle = tasks[0]["cycle_id"]
        cycle = str(UUID(cycle))
        task = self.json_request("POST", f"/cycles/{cycle}/claim")
        if task.get("status") == "completed":
            return task["result"]
        sport, run_id = task["sport"], str(UUID(task["run_id"]))
        if sport not in ("nfl", "mlb") or task["cycle_id"] != cycle or not isinstance(task["attempt_id"], int):
            raise WorkerError("Invalid worker task")
        owner = str(UUID(task["owner_token"]))
        self.reporter.task = task
        self.reporter.sequence = task.get("telemetry_sequence", 0)
        self.reporter.stage = "downloading"
        package = self.repo / "ml" / sport
        if source_hash(package, sport) != task["source_hash"]:
            raise WorkerError("Training source differs from the prepared task; use the matching checkout")
        root = self.workdir / run_id
        private_directory(root)
        state = root / "task.json"
        with state.open("w") as output:
            os.chmod(state, 0o600)
            json.dump(task, output)
        route = f"/cycles/{cycle}/attempts/{task['attempt_id']}"
        dataset, schema = root / "training-data.csv", root / "feature-schema.yaml"
        self.download(route + "/inputs/dataset", owner, task["dataset"], dataset)
        self.download(route + "/inputs/schema", owner, task["schema"], schema)
        self.reporter.log = root / "training.log"
        self.reporter.send()
        artifacts = root / "artifacts" / run_id
        if not (artifacts / "manifest.json").is_file():
            if artifacts.exists():
                shutil.rmtree(artifacts)
            options = task["options"]
            command = [sys.executable, "-m", f"picksports_{sport}_ml", "train", "--input", str(dataset),
                       "--schema", str(schema), "--expected-dataset-sha256", task["dataset"]["sha256"],
                       "--output-dir", str(root / "artifacts"), "--run-id", run_id]
            if sport == "nfl":
                command += ["--tune" if options["tune"] else "--no-tune", "--explain" if options["explain"] else "--no-explain"]
            # Child Python receives no application, database, storage, or worker credential environment.
            environment = {key: os.environ[key] for key in ("PATH", "HOME", "TMPDIR", "LANG", "LC_ALL") if key in os.environ}
            environment.update({"PYTHONPATH": str(package / "src"), "PYTHONUNBUFFERED": "1"})
            environment.update({key: str(options["threads"]) for key in ("OMP_NUM_THREADS", "OPENBLAS_NUM_THREADS", "MKL_NUM_THREADS", "NUMEXPR_NUM_THREADS")})
            try:
                with (root / "training.log").open("ab") as log:
                    os.chmod(root / "training.log", 0o600)
                    self.reporter.stage = "training_and_evaluation"
                    self.reporter.send()
                    with subprocess.Popen(command, cwd=package, env=environment, stdout=log, stderr=subprocess.STDOUT,
                                          start_new_session=True) as process:
                        self.reporter.pid = process.pid
                        try:
                            code = process.wait(timeout=options["timeout_seconds"])
                        except subprocess.TimeoutExpired:
                            os.killpg(process.pid, 9)
                            process.wait()
                            raise
                        finally:
                            self.reporter.pid = None
                        if code:
                            raise subprocess.CalledProcessError(code, command)
            except (subprocess.CalledProcessError, subprocess.TimeoutExpired):
                self.reporter.send()
                self.json_request("POST", route + "/fail", headers={"X-Training-Owner": owner})
                raise WorkerError("Training failed; inspect the private training.log") from None
        self.reporter.stage = "uploading"
        self.reporter.send()
        files = inventory(artifacts)
        for entry in files:
            self.upload(route + "/files", owner, artifacts, entry)
        self.reporter.stage = "completing"
        self.reporter.send()
        result = self.json_request("POST", route + "/complete", headers={
            "X-Training-Owner": owner, "X-Result-SHA256": result_hash(files),
        })
        with (root / "result.json").open("w") as output:
            os.chmod(root / "result.json", 0o600)
            json.dump(result, output)
        return result


class Reporter:
    """Best-effort snapshots; telemetry failure never changes training or renews a lease."""
    def __init__(self, worker):
        self.worker, self.task, self.log, self.pid = worker, None, None, None
        self.stage, self.sequence = "claimed", 0
        self.done, self.sending = threading.Event(), threading.Lock()
        self.thread = threading.Thread(target=self.loop, daemon=True)

    def start(self):
        self.send()
        self.thread.start()

    def stop(self):
        self.done.set()
        self.thread.join(timeout=12)

    def loop(self):
        while not self.done.wait(30):
            self.send()

    def metrics(self):
        disk = shutil.disk_usage(self.worker.workdir)
        rss, memory = None, None
        try:
            memory = os.sysconf("SC_PHYS_PAGES") * os.sysconf("SC_PAGE_SIZE")
            memory = memory if memory > 0 else None
        except (ValueError, OSError):
            pass
        if self.pid:
            try:
                rss = int(subprocess.check_output(["ps", "-o", "rss=", "-p", str(self.pid)], timeout=2,
                                                 env={"PATH": os.environ.get("PATH", "/usr/bin:/bin")})) * 1024
            except (ValueError, OSError, subprocess.SubprocessError):
                pass
        return {"version": "picksports-worker/2", "cpu_count": os.cpu_count(), "load_one": os.getloadavg()[0],
                "disk_free_bytes": disk.free, "disk_total_bytes": disk.total,
                "memory_total_bytes": memory, "training_rss_bytes": rss}

    def send(self):
        if not self.sending.acquire(blocking=False):
            return
        try:
            self.worker.json_request("POST", "/heartbeat", payload={"metrics": self.metrics()}, timeout=5)
            task = self.task
            if not task:
                return
            tail = ""
            if self.log and self.log.is_file():
                with self.log.open("rb") as handle:
                    handle.seek(max(0, self.log.stat().st_size - 8192))
                    tail = handle.read(8192).decode("utf-8", errors="replace")
            tail = tail.replace(self.worker.token, "[redacted]").replace(task["owner_token"], "[redacted]")
            self.sequence += 1
            self.worker.json_request("POST", f"/cycles/{task['cycle_id']}/attempts/{task['attempt_id']}/progress",
                                     headers={"X-Training-Owner": task["owner_token"]},
                                     payload={"sequence": self.sequence, "stage": self.stage, "log_tail": tail}, timeout=5)
        except (WorkerError, OSError, ValueError, KeyError, TypeError):
            pass
        finally:
            self.sending.release()


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--url", required=True, help="HTTPS origin of PickSports")
    parser.add_argument("--token-file", required=True, type=Path)
    parser.add_argument("--repo", required=True, type=Path, help="Matching PickSports source checkout")
    parser.add_argument("--workdir", type=Path, default=Path.home() / ".local/share/picksports-worker")
    parser.add_argument("--cycle", help="Optional assigned cycle UUID")
    args = parser.parse_args()
    try:
        worker = Worker(args.url, private_token(args.token_file), args.repo, args.workdir)
        print(json.dumps(worker.run(args.cycle), sort_keys=True))
    except (WorkerError, OSError, ValueError, KeyError, TypeError):
        # Never include HTTP headers, bearer credentials, or raw request/error bodies in console output.
        print("Worker stopped; verify credentials, matching source, task state, and private logs.", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
