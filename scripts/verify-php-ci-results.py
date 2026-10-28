#!/usr/bin/env python3
"""Fail CI if sharding drops, duplicates, skips, or fails a discovered PHP test."""
import argparse
from collections import Counter
from pathlib import Path
import xml.etree.ElementTree as ET


def elements(path, name):
    return [node for node in ET.parse(path).getroot().iter() if node.tag.rsplit('}', 1)[-1] == name]


def discovered(path):
    ids = [node.attrib['id'] for node in elements(path, 'testMethod')]
    if not ids or len(ids) != len(set(ids)):
        raise ValueError(f'{path.name}: empty or duplicate test discovery')
    return set(ids)


def normalized_class(name):
    return name.removeprefix('P\\')


def verify(directory, shards):
    full = discovered(directory / 'full.xml')
    planned = Counter()
    executed = Counter()
    identities = set()
    assertions = 0
    for shard in range(1, shards + 1):
        selected = discovered(directory / f'planned-{shard}.xml')
        planned.update(selected)
        expected_classes = Counter(normalized_class(test.split('::', 1)[0]) for test in selected)
        actual_classes = Counter()
        cases = elements(directory / f'junit-{shard}.xml', 'testcase')
        for case in cases:
            cls = normalized_class(case.attrib['class'])
            identity = (cls, case.attrib['name'])
            if identity in identities:
                raise ValueError(f'Duplicate executed test: {identity}')
            identities.add(identity)
            if any(node.tag.rsplit('}', 1)[-1] in {'skipped', 'failure', 'error'} for node in case):
                raise ValueError(f'Skipped or unsuccessful test: {identity}')
            actual_classes[cls] += 1
            assertions += int(case.attrib.get('assertions', 0))
        if actual_classes != expected_classes:
            raise ValueError(f'Shard {shard}: executed class/test counts differ from discovery')
        executed.update(actual_classes)
    if set(planned) != full or any(count != 1 for count in planned.values()):
        raise ValueError('Shard discovery does not cover every full-suite test exactly once')
    return f'PHP CI verified: {len(full)} tests, {assertions} assertions, {shards} shards; no missing, duplicate, skipped or failed tests.'


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('directory', type=Path)
    parser.add_argument('--shards', type=int, default=4)
    args = parser.parse_args()
    if args.shards < 1:
        parser.error('--shards must be positive')
    try:
        print(verify(args.directory, args.shards))
    except (ValueError, KeyError, OSError, ET.ParseError) as error:
        parser.exit(1, f'PHP CI verification failed: {error}\n')


if __name__ == '__main__':
    main()
