#!/usr/bin/env python3
"""Independent offline archive/Requires-Dist check; does not install/import wheels."""
import argparse
import json
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ml_runtime_contract import TARGET, dependency_graph, inspect_wheel, sha256, source_roots


def verify(directory, source, manifest_name='runtime-manifest.json'):
    directory, source = Path(directory), Path(source)
    manifest = json.loads((directory / manifest_name).read_text())
    assert manifest['target'] == TARGET and manifest['minimum_glibc'] == '2.28'
    roots, bindings, projects = source_roots(source)
    assert {str(x) for x in roots} == set(manifest['root_requirements'])
    assert projects == manifest['projects']
    assert all(manifest['source_bindings'].get(name) == digest for name, digest in bindings.items())
    for name, digest in manifest['source_bindings'].items():
        path = source / name
        assert not path.is_symlink() and path.resolve().is_relative_to(source.resolve())
        assert sha256(path) == digest
    records = {}
    filenames = {x['filename'] for x in manifest['wheels'].values()}
    assert {p.name for p in (directory / 'wheelhouse').iterdir()} == filenames
    for path in sorted((directory / 'wheelhouse').iterdir()):
        record = inspect_wheel(path)
        assert record['name'] not in records
        expected = manifest['wheels'][record['name']]
        assert all(expected[key] == value for key, value in record.items())
        assert expected['published_artifact']['sha256'] == record['sha256']
        assert expected['published_artifact']['bytes'] == record['bytes']
        records[record['name']] = record
    required, missing, edges = dependency_graph(records, roots)
    assert not missing and set(required) == set(records)
    assert {json.dumps(x, sort_keys=True) for x in edges} == {
        json.dumps(x, sort_keys=True) for x in manifest['active_requires_dist_edges']}
    expected_lock = ''.join(f"{name}=={record['version']} --hash=sha256:{record['sha256']}\n"
                            for name, record in sorted(records.items()))
    lock = directory / 'requirements-linux-aarch64-cp312.lock'
    assert lock.read_text() == expected_lock and sha256(lock) == manifest['lock_sha256']
    assert 'xgboost-cpu' in records and 'xgboost' not in records
    assert not any(name.startswith(('nvidia-', 'cuda-')) for name in records)
    return {'verified': True, 'packages': len(records), 'requires_dist_edges': len(edges),
            'wheel_bytes': sum(x['bytes'] for x in records.values()),
            'aarch64_elf_members': sum(x['aarch64_elf_members'] for x in records.values()),
            'manifest_sha256': sha256(directory / manifest_name), 'installed_or_imported': False,
            'actual_target_import_abi_numeric_canary': False}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--artifact', type=Path, required=True)
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--manifest-name', default='runtime-manifest.json')
    args = parser.parse_args()
    print(json.dumps(verify(args.artifact, args.source, args.manifest_name), indent=2))
