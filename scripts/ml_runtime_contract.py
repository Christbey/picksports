"""Static wheel/target metadata checks. Never imports scientific wheel contents."""
from collections import defaultdict, deque
from email.parser import BytesParser
from pathlib import Path, PurePosixPath
import hashlib
import json
import re
import tomllib
import zipfile

try:
    from packaging.requirements import Requirement
    from packaging.specifiers import SpecifierSet
    from packaging.tags import compatible_tags, cpython_tags, parse_tag
    from packaging.utils import canonicalize_name, parse_wheel_filename
    from packaging.version import Version
except ImportError:
    # Existing pip tooling only; no bootstrap download or dependency installation.
    from pip._vendor.packaging.requirements import Requirement
    from pip._vendor.packaging.specifiers import SpecifierSet
    from pip._vendor.packaging.tags import compatible_tags, cpython_tags, parse_tag
    from pip._vendor.packaging.utils import canonicalize_name, parse_wheel_filename
    from pip._vendor.packaging.version import Version

TARGET = {
    'implementation_name': 'cpython', 'implementation_version': '3.12.3',
    'os_name': 'posix', 'platform_machine': 'aarch64',
    'platform_python_implementation': 'CPython', 'platform_system': 'Linux',
    'python_full_version': '3.12.3', 'python_version': '3.12', 'sys_platform': 'linux',
    # These production fields were not observed. Refuse any marker depending on them.
    'platform_release': '', 'platform_version': '', 'extra': '',
}
PLATFORMS = [f'manylinux_2_{minor}_aarch64' for minor in range(28, 16, -1)] + ['manylinux2014_aarch64']
SUPPORTED_TAGS = set(cpython_tags((3, 12), abis=['cp312'], platforms=PLATFORMS)) | set(
    compatible_tags((3, 12), interpreter='cp312', platforms=PLATFORMS))
TOOL_ROOTS = ['pip==26.2.2', 'setuptools==84.1.0', 'wheel==0.46.2']
MAX_WHEEL = 256 * 1024 * 1024
MAX_METADATA = 2 * 1024 * 1024


def sha256(path):
    digest = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def marker_applies(requirement, extras=()):
    if requirement.url:
        raise ValueError('Direct URL dependencies require a separately reviewed artifact contract')
    if requirement.marker is None:
        return True
    marker = str(requirement.marker)
    if re.search(r'\b(platform_release|platform_version)\b', marker):
        raise ValueError('Target kernel release/version marker cannot be qualified from observed metadata')
    # Complete explicit environment: Marker.evaluate must never fill missing fields from macOS.
    return any(requirement.marker.evaluate(environment={**TARGET, 'extra': extra})
               for extra in ['', *sorted(extras)])


def source_roots(root):
    root = Path(root)
    requirements, bindings, projects = [], {}, {}
    for sport in ['mlb', 'nfl']:
        prefix = root / 'ml' / sport
        project = tomllib.loads((prefix / 'pyproject.toml').read_text())
        if not SpecifierSet(project['project']['requires-python']).contains(TARGET['python_full_version']):
            raise ValueError('Application package does not support target Python')
        projects[sport] = {'name': project['project']['name'], 'version': project['project']['version']}
        for name in ['pyproject.toml', 'requirements.lock.txt', 'README.md', 'Dockerfile']:
            bindings[str((prefix / name).relative_to(root))] = sha256(prefix / name)
        direct = []
        for line in (prefix / 'requirements.lock.txt').read_text().splitlines():
            if line.strip() and not line.lstrip().startswith('#'):
                requirement = Requirement(line)
                if marker_applies(requirement):
                    # Existing direct lists must actually pin one exact version.
                    specs = list(requirement.specifier)
                    if len(specs) != 1 or specs[0].operator != '==' or '*' in specs[0].version:
                        raise ValueError('Source requirements must be exact existing pins')
                    direct.append(requirement)
        locked = {canonicalize_name(x.name): x for x in direct}
        for text in project['project']['dependencies'] + project['project'].get('optional-dependencies', {}).get('test', []):
            requirement = Requirement(text)
            if marker_applies(requirement):
                pin = locked.get(canonicalize_name(requirement.name))
                if pin is None or not requirement.specifier.contains(next(iter(pin.specifier)).version):
                    raise ValueError('Application metadata and source lock disagree')
        requirements.extend(direct)
        for text in project['build-system']['requires']:
            if text not in TOOL_ROOTS:
                raise ValueError('Build-system pin needs explicit runtime contract review')
    bindings['scripts/install-ml-packages.sh'] = sha256(root / 'scripts/install-ml-packages.sh')
    requirements.extend(Requirement(x) for x in TOOL_ROOTS)
    return list({str(x): x for x in requirements}.values()), bindings, projects


def inspect_wheel(path):
    path = Path(path)
    if path.is_symlink() or not path.is_file() or not 0 < path.stat().st_size <= MAX_WHEEL:
        raise ValueError('Wheel must be a bounded regular file')
    name, version, _, tags = parse_wheel_filename(path.name)
    if not tags.intersection(SUPPORTED_TAGS):
        raise ValueError('Wheel tags are not supported by declared Linux ARM64 CPython target')
    elf_members = 0
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        names = [x.filename for x in entries]
        if len(entries) > 20000 or len(names) != len(set(names)) or sum(x.file_size for x in entries) > 1024 ** 3:
            raise ValueError('Wheel member count/size/uniqueness budget exceeded')
        for entry in entries:
            parts = PurePosixPath(entry.filename)
            if parts.is_absolute() or '..' in parts.parts or '\\' in entry.filename:
                raise ValueError('Wheel contains an unsafe member path')
        metadata_names = [x for x in names if x.endswith('.dist-info/METADATA') and len(PurePosixPath(x).parts) == 2]
        wheel_names = [x for x in names if x.endswith('.dist-info/WHEEL') and len(PurePosixPath(x).parts) == 2]
        if len(metadata_names) != 1 or len(wheel_names) != 1:
            raise ValueError('Wheel must contain exactly one metadata identity')

        def bounded(name, maximum):
            if archive.getinfo(name).file_size > maximum:
                raise ValueError('Wheel metadata allocation budget exceeded')
            with archive.open(name) as stream:
                raw = stream.read(maximum + 1)
            if len(raw) > maximum:
                raise ValueError('Wheel metadata allocation budget exceeded')
            return BytesParser().parsebytes(raw)

        metadata = bounded(metadata_names[0], MAX_METADATA)
        wheel = bounded(wheel_names[0], 65536)
        if canonicalize_name(metadata['Name']) != name or Version(metadata['Version']) != version:
            raise ValueError('Wheel filename and metadata identity disagree')
        declared_tags = set().union(*(parse_tag(x) for x in wheel.get_all('Tag', [])))
        # The published xgboost CPU wheel declares both equivalent glibc2.17 aliases.
        def equivalent_tag(tag):
            platform = 'manylinux_2_17_aarch64' if tag.platform == 'manylinux2014_aarch64' else tag.platform
            return (tag.interpreter, tag.abi, platform)
        if {equivalent_tag(x) for x in declared_tags} != {equivalent_tag(x) for x in tags}:
            raise ValueError('Wheel filename and WHEEL tags disagree')
        requires_python = metadata.get('Requires-Python', '')
        if requires_python and not SpecifierSet(requires_python).contains(TARGET['python_full_version']):
            raise ValueError('Wheel Requires-Python excludes target Python3.12.3')
        requires_dist = metadata.get_all('Requires-Dist', [])
        for text in requires_dist:
            Requirement(text)  # Parse all declarations, including inactive extras, as data only.
        for entry in entries:
            if entry.filename.endswith('.so') or '.so.' in entry.filename:
                with archive.open(entry) as stream:
                    header = stream.read(64)
                if len(header) < 20 or header[:4] != b'\x7fELF' or header[4] != 2 or header[5] != 1:
                    raise ValueError('Native library is not a qualified ELF64 little-endian payload')
                if int.from_bytes(header[18:20], 'little') != 183:
                    raise ValueError('Native ELF payload is not AArch64')
                elf_members += 1
        if elf_members and all(tag.platform == 'any' for tag in tags):
            raise ValueError('Native payload must not masquerade as a universal wheel')
    return {'name': str(name), 'version': str(version), 'filename': path.name,
            'sha256': sha256(path), 'bytes': path.stat().st_size,
            'tags': sorted(str(tag) for tag in tags), 'requires_python': requires_python,
            'requires_dist': requires_dist, 'aarch64_elf_members': elf_members}


def dependency_graph(records, roots):
    """Compute reachable requirements using the explicit target, not pip's host markers."""
    pending = deque(roots)
    seen = set()
    requirements = defaultdict(list)
    extras = defaultdict(set)
    expanded = {}
    edges = []
    while pending:
        requirement = pending.popleft()
        name = canonicalize_name(requirement.name)
        text = str(requirement)
        if text not in seen:
            seen.add(text)
            requirements[name].append(requirement)
        extras[name].update(requirement.extras)
        record = records.get(name)
        if record is None or expanded.get(name) == frozenset(extras[name]):
            continue
        expanded[name] = frozenset(extras[name])
        for text in record['requires_dist']:
            child = Requirement(text)
            if marker_applies(child, extras[name]):
                edges.append({'from': name, 'requirement': text})
                pending.append(child)
    missing = {}
    for name, items in requirements.items():
        record = records.get(name)
        if record is None or any(not item.specifier.contains(record['version']) for item in items):
            missing[name] = name + ','.join(str(item.specifier) for item in items if str(item.specifier))
    return requirements, missing, edges


def canonical_json(value):
    return json.dumps(value, sort_keys=True, separators=(',', ':'), ensure_ascii=True).encode()
