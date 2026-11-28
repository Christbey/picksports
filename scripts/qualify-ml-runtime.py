#!/usr/bin/env python3
"""Target-only import/CLI/data-only inference canary; never trains or activates."""
import argparse
import importlib.util
import json
import os
from pathlib import Path
import sys

spec = importlib.util.spec_from_file_location('runtime_provisioning', Path(__file__).with_name('provision-ml-runtime.py'))
provision = importlib.util.module_from_spec(spec)
spec.loader.exec_module(provision)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--fixture-manifest', type=Path, required=True)
    parser.add_argument('--fixture-sha256', required=True)
    parser.add_argument('--output', type=Path, required=True, help='New private canary log directory')
    parser.add_argument('--execute', action='store_true')
    args = parser.parse_args()
    path = args.fixture_manifest
    if path.is_symlink() or path.stat().st_size > 262144 or provision.digest(path) != args.fixture_sha256:
        raise ValueError('Numeric fixture manifest size/hash mismatch')
    fixture = json.loads(path.read_text())
    if fixture['schema'] != 'offline-runtime-numeric-canary-v1' or fixture['synthetic'] is not True:
        raise ValueError('Only the explicit retained synthetic data-only canary is supported')
    root = path.parent
    allowed = {'.json', '.csv', '.npy', '.npz', '.parquet', '.yaml', '.yml'}
    if not 1 <= len(fixture['files']) <= 128:
        raise ValueError('Canary file inventory capacity exceeded')
    total = 0
    for name, expected in fixture['files'].items():
        file = provision.regular_inside(root, name)
        total += file.stat().st_size
        if file.suffix not in allowed or file.stat().st_size > 8 * 1024 * 1024 or total > 64 * 1024 * 1024:
            raise ValueError('Canary requires bounded non-pickle data files only')
        if provision.digest(file) != expected:
            raise ValueError('Canary data hash mismatch')
    sports = {case['sport'] for case in fixture['cases']}
    if not sports.issubset({'mlb', 'nfl'}) or len(sports) != len(fixture['cases']):
        raise ValueError('Canary cases must name each supported tabular package at most once')
    report = {'mode': 'execute' if args.execute else 'plan_only', 'provided_sports': sorted(sports),
              'missing_sports': sorted({'mlb', 'nfl'} - sports), 'actual_target_errors': provision.actual_target_errors(),
              'checks_passed': False, 'production_or_php_interop_qualified': False, 'activation_performed': False}
    if not args.execute:
        print(json.dumps(report, indent=2))
        return 0
    if report['actual_target_errors'] or sys.prefix == sys.base_prefix:
        raise RuntimeError('Requires the actual qualified target versioned venv, before any scientific import')
    output = args.output.absolute()
    if output.exists() or not output.parent.is_dir() or output.parent.is_symlink():
        raise ValueError('Canary output must be a new owned directory')
    os.umask(0o077)
    output.mkdir(mode=0o700)
    env = {'PATH': str(Path(sys.executable).parent) + ':/usr/bin:/bin', 'HOME': str(output),
           'LANG': 'C', 'TMPDIR': str(output), 'PYTHONNOUSERSITE': '1', 'PYTHONDONTWRITEBYTECODE': '1',
           'AWS_EC2_METADATA_DISABLED': 'true', 'OMP_NUM_THREADS': '1', 'OPENBLAS_NUM_THREADS': '1',
           'MKL_NUM_THREADS': '1', 'NUMBA_NUM_THREADS': '1'}
    import_code = '''import importlib,json,pathlib,sys
names=['numpy','scipy','pandas','sklearn','pyarrow','xgboost','optuna','shap','picksports_mlb_ml','picksports_nfl_ml']
origins={}
for name in names:
 module=importlib.import_module(name)
 origin=pathlib.Path(module.__file__).resolve()
 assert origin.is_relative_to(pathlib.Path(sys.prefix).resolve()),name
 origins[name]=str(origin)
import xgboost
assert xgboost.build_info().get('USE_CUDA') is False
print(json.dumps({'module_origins':origins,'cpu_build':True}))
'''
    try:
        provision.run_bounded([sys.executable, '-I', '-B', '-c', import_code], env,
                              output / 'imports.log', timeout=60, maximum=65536)
        for sport in ['mlb', 'nfl']:
            provision.run_bounded([sys.executable, '-I', '-B', '-m', f'picksports_{sport}_ml', '--help'],
                                  env, output / (sport + '-help.log'), timeout=30, maximum=65536)
        for case in fixture['cases']:
            sport = case['sport']
            for key in ['input', 'batch_input', 'expected_output']:
                if case[key] not in fixture['files']:
                    raise ValueError('Canary consumed input must belong to the pinned inventory')
            expected = json.loads(provision.regular_inside(root, case['expected_output']).read_text())
            directory = root / case['run_directory']
            if directory.is_symlink() or not directory.is_dir() or not directory.resolve().is_relative_to(root.resolve()):
                raise ValueError('Unsupported canary run directory')
            for item in directory.rglob('*'):
                if item.is_symlink() or (item.is_file() and item.relative_to(root).as_posix() not in fixture['files']):
                    raise ValueError('Every consumed run file must belong to the pinned inventory')
            for key, rows in [('input', 1), ('batch_input', 3)]:
                log = output / (sport + '-' + key + '.json')
                provision.run_bounded([sys.executable, '-I', '-B', '-m', f'picksports_{sport}_ml', 'predict',
                                       '--run-dir', str(directory), '--input', str(root / case[key])],
                                      env, log, timeout=30, maximum=65536)
                actual = json.loads(log.read_text())
                if not isinstance(actual, list) or len(actual) != rows or any(
                    not isinstance(row, dict) or any(name not in row or row[name] != value for name, value in expected.items())
                    for row in actual
                ):
                    raise ValueError('Existing exact synthetic prediction contract changed; no new epsilon or rounding is applied')
        report['checks_passed'] = sports == {'mlb', 'nfl'}
        report['provided_cases_passed'] = True
    finally:
        (output / 'result.json').write_text(json.dumps(report, indent=2) + '\n')
    print(json.dumps(report, indent=2))
    # Missing a sport never becomes a green whole-runtime canary.
    return 0 if report['checks_passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
