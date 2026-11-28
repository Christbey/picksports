#!/usr/bin/env python3
"""Download inert binary wheels, then independently close explicit target markers."""
import argparse
import datetime
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import urllib.parse
import urllib.request

sys.path.insert(0, str(Path(__file__).resolve().parent))
from ml_runtime_contract import (PLATFORMS, TARGET, canonical_json, canonicalize_name,
                                 dependency_graph, inspect_wheel, sha256, source_roots)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--source', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    source, output = args.source.resolve(), args.output.resolve()
    output.mkdir(parents=True, exist_ok=True, mode=0o700)
    output.chmod(0o700)
    wheelhouse, metadata = output / 'wheelhouse', output / 'metadata'
    wheelhouse.mkdir(exist_ok=True)
    metadata.mkdir(exist_ok=True)
    assert not (output / 'runtime-manifest.json').exists(), 'Preserve existing sealed candidate'
    roots, bindings, projects = source_roots(source)
    serial = max([int(p.stem.rsplit('-', 1)[-1]) for p in metadata.glob('closure-download-*.log')] or [0])

    def download(requirements):
        nonlocal serial
        serial += 1
        log = metadata / f'closure-download-{serial:03}.log'
        assert not log.exists(), 'Preserve prior download attempt logs'
        command = [sys.executable, '-I', '-B', '-m', 'pip', '--isolated', '--disable-pip-version-check',
                   'download', '--index-url', 'https://pypi.org/simple', '--only-binary=:all:',
                   '--no-deps', '--no-cache-dir', '--retries=0', '--timeout=30',
                   '--python-version=3.12.3', '--implementation=cp', '--abi=cp312', '--abi=abi3', '--abi=none',
                   '--dest', str(wheelhouse)]
        command += ['--platform=' + platform for platform in PLATFORMS]
        command += requirements
        env = {'PATH': os.environ['PATH'], 'HOME': str(output), 'LANG': 'C', 'TMPDIR': str(output),
               'PIP_CONFIG_FILE': '/dev/null', 'PYTHONDONTWRITEBYTECODE': '1'}
        with log.open('x') as stream:
            result = subprocess.run(command, env=env, stdout=stream, stderr=subprocess.STDOUT, timeout=300)
        (metadata / f'closure-download-{serial:03}.json').write_text(json.dumps({
            'exit': result.returncode, 'requirements': requirements, 'log_sha256': sha256(log),
            'scope': 'Normal public package-index binary download only; no dependencies resolved by host markers or code imported'}, indent=2) + '\n')
        if result.returncode:
            raise RuntimeError(f'Binary wheel selection failed; retained log {log}')

    for iteration in range(12):
        records = {}
        for path in sorted(wheelhouse.glob('*.whl')):
            record = inspect_wheel(path)
            if record['name'] in records:
                raise ValueError('Multiple active versions of one package')
            records[record['name']] = record
        required, missing, edges = dependency_graph(records, roots)
        if not missing:
            break
        archive = metadata / f'replaced-candidates-{iteration:02}'
        for name in missing:
            if name in records:
                archive.mkdir(exist_ok=True)
                shutil.move(str(wheelhouse / records[name]['filename']), str(archive / records[name]['filename']))
        download([missing[name] for name in sorted(missing)])
    else:
        raise RuntimeError('Target dependency closure did not converge within bounded rounds')

    # Keep an exact active wheelhouse. Retain unused tentative artifacts separately, never silently install them.
    unused = set(records) - set(required)
    for name in sorted(unused):
        archive = metadata / 'unreachable-candidates'
        archive.mkdir(exist_ok=True)
        shutil.move(str(wheelhouse / records[name]['filename']), str(archive / records[name]['filename']))
        del records[name]
    required, missing, edges = dependency_graph(records, roots)
    assert not missing and set(required) == set(records)

    # Pin each selected ordinary distribution file against public pinned-version metadata.
    # Only filename/URL/hash/size are used: no advisory service or vulnerability inventory is queried.
    opener = urllib.request.build_opener(urllib.request.ProxyHandler({}))
    for name, record in sorted(records.items()):
        url = 'https://pypi.org/pypi/' + urllib.parse.quote(name, safe='') + '/' + urllib.parse.quote(record['version'], safe='') + '/json'
        request = urllib.request.Request(url, headers={'Accept': 'application/json', 'User-Agent': 'offline-runtime-artifact-metadata/1'})
        with opener.open(request, timeout=30) as response:
            raw = response.read(2 * 1024 * 1024 + 1)
        if len(raw) > 2 * 1024 * 1024:
            raise ValueError('Pinned package artifact metadata exceeds budget')
        data = json.loads(raw)
        matching = [entry for entry in data['urls'] if entry['filename'] == record['filename']]
        if len(matching) != 1:
            raise ValueError('Selected wheel is not a unique published pinned artifact')
        entry = matching[0]
        if entry['digests']['sha256'] != record['sha256'] or entry['size'] != record['bytes']:
            raise ValueError('Downloaded wheel differs from published artifact hash/size')
        if urllib.parse.urlparse(entry['url']).hostname != 'files.pythonhosted.org':
            raise ValueError('Unexpected public wheel origin')
        record['published_artifact'] = {'metadata_url': url, 'url': entry['url'],
                                        'sha256': entry['digests']['sha256'], 'bytes': entry['size']}
        (metadata / (name + '-' + record['version'] + '-artifact.json')).write_text(
            json.dumps(record['published_artifact'], indent=2) + '\n')

    assert source_roots(source)[1] == bindings, 'Source pins changed during preparation'
    lock = ''.join(f"{name}=={record['version']} --hash=sha256:{record['sha256']}\n"
                   for name, record in sorted(records.items()))
    (output / 'requirements-linux-aarch64-cp312.lock').write_text(lock)
    manifest = {'schema': 1, 'created_at_utc': datetime.datetime.now(datetime.timezone.utc).isoformat(),
                'target': TARGET, 'minimum_glibc': '2.28', 'platforms': PLATFORMS,
                'source_bindings': bindings, 'projects': projects,
                'root_requirements': [str(x) for x in roots], 'wheels': records,
                'active_requires_dist_edges': edges,
                'lock_sha256': sha256(output / 'requirements-linux-aarch64-cp312.lock'),
                'host_tool': {'python_version': sys.version.split()[0], 'executable': sys.executable,
                              'scope': 'Downloader/static inspector only; not target import/runtime evidence'},
                'qualification': {'downloaded_code_executed': False, 'installed': False,
                                  'actual_linux_import_or_abi_canary': False, 'no_fit_canary': False},
                'limits': ['Explicit target metadata closure and archive/ELF-header qualification only, not import/ABI/numeric compatibility.',
                           'Production libc, shared-library availability, effective interpreter/worker binding and rollback remain deployment acceptance gates.',
                           'No advisory audit, production commands, settings, uploads, installs, fitting or pickle loading.']}
    (output / 'runtime-manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
    print(json.dumps({'packages': len(records), 'bytes': sum(x['bytes'] for x in records.values()),
                      'aarch64_elf_members': sum(x['aarch64_elf_members'] for x in records.values()),
                      'manifest_sha256': sha256(output / 'runtime-manifest.json'),
                      'lock_sha256': manifest['lock_sha256']}))


if __name__ == '__main__':
    main()
