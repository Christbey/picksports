#!/usr/bin/env python3
"""Build a portable, explicitly synthetic PHP/Python CI interoperability fixture.

Default mode performs ONE tiny synthetic fit using the existing package fixtures
(112 rows, seed 20260726, eight trees, no tuning or explanations). It is not an
empirical/production approval. --reuse-run-dir only admits/copies an existing
synthetic numeric run and does not fit anything.
"""
import argparse
import csv
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import tempfile
import uuid

SCHEMA = 'picksports-nfl-ci-interop-v1'
MAX_DATASET = 2 * 1024 * 1024
MAX_TOTAL = 32 * 1024 * 1024
MAX_METADATA = 2 * 1024 * 1024
MAX_COMPONENT = 8 * 1024 * 1024


def copy_bounded(source, destination, maximum, expected_bytes=None, expected_sha256=None):
    source, destination = Path(source), Path(destination)
    initial = source.lstat()
    if not stat.S_ISREG(initial.st_mode) or initial.st_size > maximum:
        raise ValueError('Synthetic fixture source is not a bounded regular file')
    if expected_bytes is not None and initial.st_size != expected_bytes:
        raise ValueError('Synthetic fixture source size differs from its inventory')
    digest = hashlib.sha256()
    destination.parent.mkdir(parents=True, exist_ok=True)
    created = False
    try:
        with source.open('rb') as incoming, destination.open('xb') as outgoing:
            created = True
            opened = os.fstat(incoming.fileno())
            if (opened.st_dev, opened.st_ino, opened.st_size) != (initial.st_dev, initial.st_ino, initial.st_size):
                raise ValueError('Synthetic fixture identity changed before copy')
            remaining = initial.st_size
            while remaining:
                chunk = incoming.read(min(65536, remaining))
                if not chunk:
                    raise ValueError('Synthetic fixture source shrank during copy')
                outgoing.write(chunk)
                digest.update(chunk)
                remaining -= len(chunk)
            if incoming.read(1):
                raise ValueError('Synthetic fixture source grew during copy')
            final = os.fstat(incoming.fileno())
            if (final.st_size, final.st_mtime_ns, final.st_ctime_ns) != (opened.st_size, opened.st_mtime_ns, opened.st_ctime_ns):
                raise ValueError('Synthetic fixture source changed during copy')
        if expected_sha256 is not None and digest.hexdigest() != expected_sha256:
            raise ValueError('Synthetic fixture source digest differs from its inventory')
    except BaseException:
        if created:
            destination.unlink(missing_ok=True)
        raise
    return digest.hexdigest()


def inventory_total(inventory, required):
    if not isinstance(inventory, dict) or set(inventory) != set(required):
        raise ValueError('Synthetic numeric inventory differs from the fixed package set')
    total = 0
    for name, entry in inventory.items():
        cap = MAX_COMPONENT if name.startswith(('models/', 'calibrators/')) or name == 'preprocessor.json' else MAX_METADATA
        if not isinstance(entry, dict) or type(entry.get('bytes')) is not int or not 0 <= entry['bytes'] <= cap:
            raise ValueError('Synthetic numeric component exceeds its byte budget')
        total += entry['bytes']
    if total > MAX_TOTAL:
        raise ValueError('Synthetic numeric run exceeds its total byte budget')
    return total


def create_tiny_run(workspace):
    # Lazy scientific imports keep pure structure/copy tests dependency-free.
    from picksports_nfl_ml.pipeline import train
    source = Path(__file__).resolve().parents[1] / 'ml/nfl/tests/conftest.py'
    spec = importlib.util.spec_from_file_location('picksports_ci_synthetic_inputs', source)
    fixtures = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(fixtures)
    frame = fixtures.synthetic_frame.__wrapped__()
    if len(frame) != 112 or sorted(frame['season'].unique().tolist()) != list(range(2018, 2025)):
        raise ValueError('Reviewed tiny synthetic fixture dimensions changed')
    schema = fixtures.schema_path.__wrapped__(workspace)
    from picksports_nfl_ml.schema import FeatureSchema
    training = FeatureSchema.load(schema).training
    if training['xgboost_classifier']['n_estimators'] != 8 or training['xgboost_regressor']['n_estimators'] != 8:
        raise ValueError('Reviewed tiny synthetic tree budget changed')
    dataset = workspace / 'source.csv'
    frame.to_csv(dataset, index=False)
    result = train(input_path=dataset, schema_path=schema, output_dir=workspace / 'fitted',
                   run_id=str(uuid.uuid4()), seed=20260726, tuning_enabled=False, explanations_enabled=False)
    return Path(result['run_dir']), dataset


def prepare_fixture(run, dataset, destination, source_mode):
    from picksports_nfl_ml.artifacts import REQUIRED_ARTIFACTS, verify_artifact_inventory
    from picksports_nfl_ml.numeric_artifacts import read_json
    from picksports_nfl_ml.inference import InferenceBundle
    import pandas as pd

    run, dataset, destination = Path(run), Path(dataset), Path(destination)
    if run.is_symlink() or not run.is_dir():
        raise ValueError('Synthetic numeric run must be an owned regular directory')
    destination.mkdir(mode=0o700)
    try:
        # Freeze bounded manifest bytes once before interpreting inventory or model state.
        manifest_hash = copy_bounded(run / 'manifest.json', destination / 'run/manifest.json', MAX_METADATA)
        manifest = read_json(destination / 'run/manifest.json', max_bytes=MAX_METADATA, max_tokens=50000)
        inventory = manifest['artifacts']
        inventory_total(inventory, REQUIRED_ARTIFACTS)
        verify_artifact_inventory(run, manifest)
        dataset_hash = copy_bounded(dataset, destination / 'dataset.csv', MAX_DATASET,
                                    expected_sha256=manifest['dataset_hash'])
        for name in sorted(inventory):
            entry = inventory[name]
            copy_bounded(run / name, destination / 'run' / name, MAX_COMPONENT,
                         expected_bytes=entry['bytes'], expected_sha256=entry['sha256'])
        # Real numeric loader + genuine model inference; no fake CLI or approval state.
        bundle = InferenceBundle.load(destination / 'run')
        with (destination / 'dataset.csv').open(newline='') as handle:
            row = next(csv.DictReader(handle))
        output = bundle.predict(pd.DataFrame([row]))[0]
        expected = json.dumps(output, indent=2, allow_nan=False) + '\n'
        if len(expected.encode()) > 65536:
            raise ValueError('Synthetic expected output exceeds its metadata bound')
        (destination / 'expected-output.json').write_text(expected)
        descriptor = {'schema_version': SCHEMA, 'synthetic': True, 'source_mode': source_mode,
                      'run_directory': 'run', 'dataset': 'dataset.csv', 'expected_output': 'expected-output.json',
                      'manifest_sha256': manifest_hash, 'dataset_sha256': dataset_hash,
                      'expected_output_sha256': hashlib.sha256(expected.encode()).hexdigest()}
        (destination / 'fixture.json').write_text(json.dumps(descriptor, indent=2) + '\n')
    except BaseException:
        shutil.rmtree(destination)
        raise
    return descriptor


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--reuse-run-dir', type=Path)
    parser.add_argument('--dataset', type=Path)
    args = parser.parse_args()
    if args.output.exists() or args.output.is_symlink():
        parser.error('Output must be a fresh private fixture directory')
    if bool(args.reuse_run_dir) != bool(args.dataset):
        parser.error('--reuse-run-dir and --dataset must be supplied together')
    if args.reuse_run_dir:
        descriptor = prepare_fixture(args.reuse_run_dir, args.dataset, args.output, 'retained_synthetic_no_fit')
    else:
        with tempfile.TemporaryDirectory(prefix='picksports-ci-tiny-fit-') as temporary:
            run, dataset = create_tiny_run(Path(temporary))
            descriptor = prepare_fixture(run, dataset, args.output, 'tiny_synthetic_ci_fit')
    print(json.dumps(descriptor))


if __name__ == '__main__':
    main()
