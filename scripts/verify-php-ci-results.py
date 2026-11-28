#!/usr/bin/env python3
"""Fail CI if sharding drops, duplicates, skips, or fails a discovered PHP test."""
import argparse
import json
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


def canonical_execution(path, selected):
    with path.open() as source:
        records = [json.loads(line) for line in source]
    if len(records) < 2 or records[0] != {'schema': 1, 'event': 'start'} or records[-1] != {'event': 'end'}:
        raise ValueError(f'{path.name}: incomplete canonical execution log')
    passed = Counter()
    finished = Counter()
    for record in records[1:-1]:
        if set(record) != {'event', 'id'} or not isinstance(record['id'], str):
            raise ValueError(f'{path.name}: malformed canonical event')
        identity = record['id']
        if identity not in selected:
            raise ValueError(f'{path.name}: executed identity not in shard discovery')
        if record['event'] == 'passed':
            passed[identity] += 1
            if passed[identity] != 1 or finished[identity]:
                raise ValueError(f'{path.name}: duplicate or out-of-order passed identity')
        elif record['event'] == 'finished':
            finished[identity] += 1
            if passed[identity] != 1 or finished[identity] != 1:
                raise ValueError(f'{path.name}: duplicate or unsuccessful finished identity')
        else:
            raise ValueError(f'{path.name}: unsuccessful or unknown canonical event')
    expected = Counter({identity: 1 for identity in selected})
    if passed != expected or finished != expected:
        raise ValueError(f'{path.name}: canonical executed identities differ from discovery')


def verify(directory, shards):
    full = discovered(directory / 'full.xml')
    planned = Counter()
    executed = Counter()
    identities = set()
    assertions = 0
    for shard in range(1, shards + 1):
        selected = discovered(directory / f'planned-{shard}.xml')
        canonical_execution(directory / f'events-{shard}.jsonl', selected)
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
    except (ValueError, KeyError, TypeError, OSError, ET.ParseError) as error:
        parser.exit(1, f'PHP CI verification failed: {error}\n')


if __name__ == '__main__':
    main()
