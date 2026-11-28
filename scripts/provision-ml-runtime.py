#!/usr/bin/env python3
"""Explicit, offline, unactivated versioned runtime provisioning; plan is default."""
import argparse
import ctypes.util
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import platform
import selectors
import shutil
import signal
import subprocess
import sys
import time


def digest(path):
    result = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            result.update(block)
    return result.hexdigest()


def actual_target_errors():
    errors = []
    if platform.system() != 'Linux' or platform.machine() != 'aarch64':
        errors.append('Requires actual Linux aarch64, not cross-platform metadata or a macOS venv')
    if sys.implementation.name != 'cpython' or sys.version_info[:3] != (3, 12, 3):
        errors.append('Requires the explicit CPython3.12.3 base, not a different interpreter epoch')
    libc, version = platform.libc_ver()
    try:
        valid_libc = libc == 'glibc' and tuple(int(x) for x in version.split('.')[:2]) >= (2, 28)
    except ValueError:
        valid_libc = False
    if not valid_libc:
        errors.append('Requires verified glibc>=2.28; production libc was not observed')
    if not ctypes.util.find_library('gomp'):
        errors.append('Requires existing OS libgomp, as in the existing package Dockerfiles; no apt mutation is performed')
    if hasattr(os, 'geteuid') and os.geteuid() == 0:
        errors.append('Use an isolated nonroot provisioning identity')
    return errors


def regular_inside(parent, relative):
    parts = PurePosixPath(relative)
    if parts.is_absolute() or '..' in parts.parts or '\\' in relative:
        raise ValueError('Unsafe manifest relative path')
    current = parent
    for part in parts.parts:
        current = current / part
        if current.is_symlink():
            raise ValueError('Manifest inputs must not be symlinks')
    if not current.is_file() or not current.resolve().is_relative_to(parent.resolve()):
        raise ValueError('Missing or outside manifest input')
    return current


def verify_inputs(manifest_path, expected_hash, source):
    manifest_path = Path(manifest_path)
    if manifest_path.is_symlink() or manifest_path.stat().st_size > 8 * 1024 * 1024:
        raise ValueError('Runtime manifest must be a bounded regular local file')
    if digest(manifest_path) != expected_hash:
        raise ValueError('Runtime manifest hash mismatch')
    manifest = json.loads(manifest_path.read_text())
    if manifest['target']['python_full_version'] != '3.12.3' or manifest['target']['platform_machine'] != 'aarch64' or manifest['target']['platform_system'] != 'Linux':
        raise ValueError('Wrong runtime target contract')
    home = manifest_path.parent
    lock = regular_inside(home, 'requirements-linux-aarch64-cp312.lock')
    if digest(lock) != manifest['lock_sha256']:
        raise ValueError('Runtime dependency lock hash mismatch')
    wheelhouse = home / 'wheelhouse'
    names = set(manifest['wheels'])
    required = {'numpy', 'scipy', 'pandas', 'scikit-learn', 'pyarrow', 'xgboost-cpu', 'optuna', 'shap', 'pip', 'setuptools', 'wheel'}
    if not required.issubset(names) or len(names) > 128 or 'xgboost' in names or any(x.startswith(('nvidia-', 'cuda-')) for x in names):
        raise ValueError('Missing scientific/build closure or an unsupported GPU distribution')
    expected_lock = ''.join(f"{name}=={record['version']} --hash=sha256:{record['sha256']}\n" for name, record in sorted(manifest['wheels'].items()))
    if lock.read_text() != expected_lock:
        raise ValueError('Lock does not exactly enumerate the reviewed wheel records')
    expected_names = {record['filename'] for record in manifest['wheels'].values()}
    if {p.name for p in wheelhouse.iterdir()} != expected_names:
        raise ValueError('Wheelhouse must contain exactly the reviewed closed artifact set')
    for record in manifest['wheels'].values():
        wheel = regular_inside(wheelhouse, record['filename'])
        if wheel.stat().st_size != record['bytes'] or digest(wheel) != record['sha256']:
            raise ValueError('Runtime wheel hash/size mismatch')
    bindings = manifest['source_bindings']
    if not 1 <= len(bindings) <= 128:
        raise ValueError('Bound source input count exceeded')
    total = 0
    for name, expected in bindings.items():
        path = regular_inside(source, name)
        total += path.stat().st_size
        if path.stat().st_size > 2 * 1024 * 1024 or total > 32 * 1024 * 1024 or digest(path) != expected:
            raise ValueError('Release source input bytes/hash mismatch')
    for sport in ['mlb', 'nfl']:
        if not any(x.startswith(f'ml/{sport}/src/') for x in bindings):
            raise ValueError('Application package source inventory is missing')
    if 'scripts/install-ml-packages.sh' not in bindings:
        raise ValueError('Existing release package installer must be bound')
    return manifest, lock, wheelhouse


def run_bounded(command, env, log_path, timeout=180, maximum=1024 * 1024):
    """Retain bounded combined output and terminate/reap only our child group."""
    process = subprocess.Popen(command, env=env, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE,
                               stderr=subprocess.STDOUT, start_new_session=True)
    selector = selectors.DefaultSelector()
    selector.register(process.stdout, selectors.EVENT_READ)
    written = 0
    deadline = time.monotonic() + timeout
    try:
        with Path(log_path).open('xb') as log:
            while selector.get_map():
                if time.monotonic() > deadline:
                    raise RuntimeError('Provisioning child exceeded its deadline')
                for key, _ in selector.select(timeout=0.1):
                    data = os.read(key.fileobj.fileno(), 65536)
                    if not data:
                        selector.unregister(key.fileobj)
                        continue
                    if written + len(data) > maximum:
                        log.write(data[:max(0, maximum - written)])
                        raise RuntimeError('Provisioning child output exceeded its bounded log')
                    log.write(data)
                    written += len(data)
            result = process.wait(timeout=max(0.1, deadline - time.monotonic()))
            if result:
                raise RuntimeError('Provisioning child failed; inspect the retained bounded log')
    finally:
        selector.close()
        if process.poll() is None:
            os.killpg(process.pid, signal.SIGTERM)
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=5)
        process.stdout.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--manifest', type=Path, required=True)
    parser.add_argument('--manifest-sha256', required=True)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--destination', type=Path, required=True, help='NEW versioned directory, never the active .venv')
    parser.add_argument('--execute', action='store_true', help='Explicit installation on a qualified isolated target ONLY')
    args = parser.parse_args()
    source = args.source.resolve()
    destination = args.destination.absolute()
    manifest, lock, wheelhouse = verify_inputs(args.manifest, args.manifest_sha256, source)
    if destination.exists() or destination.is_symlink() or not destination.parent.is_dir() or destination.parent.is_symlink():
        raise ValueError('Destination must be a new directory under an existing nonsymlink parent')
    venv = destination / '.venv'
    staged = destination / 'source'
    commands = [[sys.executable, '-I', '-m', 'venv', str(venv)],
                [str(venv / 'bin/python'), '-I', '-m', 'pip', 'install', '--no-index',
                 '--find-links', str(wheelhouse), '--require-hashes', '--only-binary=:all:',
                 '--no-deps', '-r', str(lock)],
                ['/bin/bash', str(staged / 'scripts/install-ml-packages.sh')]]
    plan = {'mode': 'execute' if args.execute else 'plan_only', 'manifest_sha256': args.manifest_sha256,
            'destination': str(destination), 'commands': commands,
            'actual_target_errors': actual_target_errors(), 'activation_performed': False,
            'numeric_canary_and_php_interop_qualified': False}
    if not args.execute:
        print(json.dumps(plan, indent=2))
        return
    if plan['actual_target_errors']:
        raise RuntimeError('; '.join(plan['actual_target_errors']))
    os.umask(0o077)
    destination.mkdir(mode=0o700)
    logs = destination / 'logs'
    logs.mkdir()
    (destination / 'plan.json').write_text(json.dumps(plan, indent=2) + '\n')
    for name in manifest['source_bindings']:
        target = staged / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(regular_inside(source, name), target)
    env = {'PATH': str(venv / 'bin') + ':/usr/bin:/bin', 'HOME': str(destination), 'LANG': 'C',
           'TMPDIR': str(destination), 'PIP_CONFIG_FILE': '/dev/null', 'PIP_NO_INDEX': '1',
           'PIP_FIND_LINKS': str(wheelhouse), 'PIP_DISABLE_PIP_VERSION_CHECK': '1',
           'PYTHONNOUSERSITE': '1', 'PYTHONDONTWRITEBYTECODE': '1',
           'ML_PYTHON_BINARY': str(venv / 'bin/python'), 'AWS_EC2_METADATA_DISABLED': 'true',
           'OMP_NUM_THREADS': '1', 'OPENBLAS_NUM_THREADS': '1', 'MKL_NUM_THREADS': '1', 'NUMBA_NUM_THREADS': '1'}
    try:
        for number, command in enumerate(commands):
            run_bounded(command, env, logs / f'{number:02}.log')
        # The existing installer does its normal app imports/pip check. This extra
        # fixed import is target-only and does not load a model or fit anything.
        run_bounded([str(venv / 'bin/python'), '-I', '-c',
                     'import numpy,scipy,pandas,sklearn,pyarrow,xgboost,optuna,shap; print("scientific imports passed")'],
                    env, logs / '03-scientific-imports.log', timeout=60)
        plan['installed_unactivated'] = True
    except BaseException:
        plan['installed_unactivated'] = False
        raise
    finally:
        plan['activation_performed'] = False
        (destination / 'result.json').write_text(json.dumps(plan, indent=2) + '\n')
    print(json.dumps(plan, indent=2))


if __name__ == '__main__':
    main()
