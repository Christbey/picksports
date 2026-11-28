#!/usr/bin/env python3
"""Run pruning contracts against an owned, socket-only, disposable Redis child."""

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import time


def run() -> int:
    root = Path(__file__).resolve().parent.parent
    server = shutil.which("redis-server")
    cli = shutil.which("redis-cli")
    if not server or not cli:
        raise RuntimeError("redis-server and redis-cli are required; no existing server is used")
    php = ["bash", "scripts/php85.sh", "vendor/bin/pest"]
    unit = subprocess.run(
        [*php, "tests/Feature/Queue/PruneStaleFootballGameDetailsJobsCommandTest.php", "--compact"],
        cwd=root, timeout=90,
    )
    if unit.returncode:
        return unit.returncode

    directory = Path(tempfile.mkdtemp(
        prefix="picksports-readiness-redis-", dir=Path(tempfile.gettempdir()).resolve(),
    ))
    directory.chmod(0o700)
    socket = directory / "redis.sock"
    log = os.open(directory / "server.log", os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    process = None
    run_id = None
    verified = False
    stopped = False
    result = 1

    def info() -> dict[str, str]:
        response = subprocess.run(
            [cli, "-s", str(socket), "--raw", "INFO", "server"],
            capture_output=True, timeout=3, check=True,
        )
        return dict(line.split(":", 1) for line in response.stdout.decode().splitlines()
                    if ":" in line and not line.startswith("#"))

    def same_child(metadata: dict[str, str]) -> bool:
        return (process is not None and metadata.get("run_id") == run_id
                and metadata.get("process_id") == str(process.pid)
                and metadata.get("tcp_port") == "0")

    try:
        process = subprocess.Popen([
            server, "--port", "0", "--unixsocket", str(socket), "--unixsocketperm", "600",
            "--save", "", "--appendonly", "no", "--maxmemory", "32mb",
            "--maxmemory-policy", "noeviction", "--daemonize", "no", "--protected-mode", "yes",
            "--maxclients", "8", "--dir", str(directory),
        ], stdout=log, stderr=subprocess.STDOUT)
        deadline = time.monotonic() + 8
        while not socket.exists() and process.poll() is None and time.monotonic() < deadline:
            time.sleep(0.05)
        metadata = info()
        run_id = metadata.get("run_id", "")
        verified = bool(re.fullmatch(r"[a-f0-9]{40}", run_id)) and same_child(metadata)
        if not verified:
            raise RuntimeError("Disposable Redis identity does not match the owned child")
        env = os.environ.copy()
        env.update(PICKSPORTS_TEST_REDIS="1", PICKSPORTS_TEST_REDIS_SOCKET=str(socket),
                   PICKSPORTS_TEST_REDIS_RUN_ID=run_id, PICKSPORTS_TEST_REDIS_PID=str(process.pid))
        result = subprocess.run(
            [*php, "tests/Integration/Redis/BoundedReadyQueuePruningTest.php", "--compact"],
            cwd=root, env=env, timeout=90,
        ).returncode
    finally:
        try:
            if process is not None and process.poll() is None:
                try:
                    if verified and not socket.is_symlink() and same_child(info()):
                        subprocess.run([cli, "-s", str(socket), "SHUTDOWN", "NOSAVE"],
                                       capture_output=True, timeout=3, check=False)
                finally:
                    if process.poll() is None:
                        # Only this unreaped child handle; never a discovered PID or shared service.
                        process.terminate()
                    try:
                        process.wait(timeout=5)
                    except subprocess.TimeoutExpired:
                        process.kill()
                        process.wait(timeout=5)
            stopped = process is None or process.poll() is not None
        finally:
            os.close(log)
            if (stopped and not directory.is_symlink()
                    and directory.name.startswith("picksports-readiness-redis-")
                    and directory.stat().st_uid == os.geteuid()):
                shutil.rmtree(directory)
            print(json.dumps({"private_fixture_identity_verified": verified,
                              "tcp_disabled": verified, "memory_cap_mib": 32,
                              "server_stopped": stopped, "owned_directory_removed": not directory.exists(),
                              "test_exit": result}))
    return result if stopped else 3


if __name__ == "__main__":
    raise SystemExit(run())
