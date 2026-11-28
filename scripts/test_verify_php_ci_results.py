import importlib.util
import json
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('ci_results', Path(__file__).with_name('verify-php-ci-results.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class PhpCiResultsTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.root.joinpath('full.xml').write_text(self.discovery(['A::one', 'B::two']))
        for shard, cls, name in [(1, 'A', 'one'), (2, 'B', 'two')]:
            self.root.joinpath(f'planned-{shard}.xml').write_text(self.discovery([f'{cls}::{name}']))
            self.root.joinpath(f'junit-{shard}.xml').write_text(f'<testsuites><testcase class="{cls}" name="{name}" assertions="2"/></testsuites>')
            self.events(shard, [f'{cls}::{name}'])

    @staticmethod
    def discovery(ids):
        from xml.sax.saxutils import quoteattr
        return '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>' + ''.join(f'<testMethod id={quoteattr(i)}/>' for i in ids) + '</tests></testSuite>'

    def events(self, shard, identities):
        records = [{'schema': 1, 'event': 'start'}]
        for identity in identities:
            records.extend([{'event': 'passed', 'id': identity}, {'event': 'finished', 'id': identity}])
        records.append({'event': 'end'})
        self.root.joinpath(f'events-{shard}.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in records))

    def test_rejects_same_class_name_substitution(self):
        self.root.joinpath('full.xml').write_text(self.discovery(['A::one', 'A::two']))
        self.root.joinpath('planned-2.xml').write_text(self.discovery(['A::two']))
        self.root.joinpath('junit-2.xml').write_text('<testsuites><testcase class="A" name="different" assertions="2"/></testsuites>')
        self.events(2, ['A::different'])
        with self.assertRaisesRegex(ValueError, 'identity not in'):
            module.verify(self.root, 2)

    def test_missing_canonical_identity_even_with_matching_junit_count(self):
        self.events(2, [])
        with self.assertRaisesRegex(ValueError, 'identities differ'):
            module.verify(self.root, 2)

    def test_duplicate_canonical_identity(self):
        self.events(2, ['B::two', 'B::two'])
        with self.assertRaisesRegex(ValueError, 'duplicate'):
            module.verify(self.root, 2)

    def test_unsuccessful_canonical_event_even_with_successful_junit(self):
        for event in ['unsuccessful', 'skipped', 'failed', 'error', 'unknown']:
            with self.subTest(event=event):
                records = [{'schema': 1, 'event': 'start'}, {'event': event, 'id': 'B::two'}, {'event': 'end'}]
                self.root.joinpath('events-2.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in records))
                with self.assertRaisesRegex(ValueError, 'unsuccessful'):
                    module.verify(self.root, 2)

    def test_arbitrary_testdox_and_multiline_dataset_identity(self):
        import xml.etree.ElementTree as ET
        identity = 'P\\Tests\\Example::test_dataset#named "quote"\nsecond line with data set #0 界'
        self.root.joinpath('full.xml').write_text(self.discovery(['A::one', identity]))
        self.root.joinpath('planned-2.xml').write_text(self.discovery([identity]))
        self.events(2, [identity])
        root = ET.Element('testsuites')
        ET.SubElement(root, 'testcase', {'class': 'Tests\\Example', 'name': 'Arbitrary human label unrelated to method', 'assertions': '2'})
        ET.ElementTree(root).write(self.root / 'junit-2.xml')
        self.assertIn('2 tests, 4 assertions', module.verify(self.root, 2))

    def test_requires_complete_fresh_machine_log(self):
        for body in ['', '{bad json}\n', json.dumps({'schema': 1, 'event': 'start'}) + '\n']:
            with self.subTest(body=body):
                self.root.joinpath('events-2.jsonl').write_text(body)
                with self.assertRaises(ValueError):
                    module.verify(self.root, 2)
        self.root.joinpath('events-2.jsonl').unlink()
        with self.assertRaises(OSError):
            module.verify(self.root, 2)

    def test_complete_disjoint_execution(self):
        self.assertIn('2 tests, 4 assertions', module.verify(self.root, 2))

    def test_missing_test(self):
        self.root.joinpath('junit-2.xml').write_text('<testsuites/>')
        with self.assertRaisesRegex(ValueError, 'counts differ'):
            module.verify(self.root, 2)

    def test_overlapping_shards(self):
        self.root.joinpath('planned-2.xml').write_text(self.discovery(['A::one']))
        with self.assertRaises(ValueError):
            module.verify(self.root, 2)

    def test_test_missing_from_all_shards(self):
        self.root.joinpath('full.xml').write_text(self.discovery(['A::one', 'B::two', 'C::three']))
        with self.assertRaisesRegex(ValueError, 'every full-suite test'):
            module.verify(self.root, 2)

    def test_skipped_or_failed_cases(self):
        for tag in ['skipped', 'failure', 'error']:
            with self.subTest(tag=tag):
                self.root.joinpath('junit-2.xml').write_text(f'<testsuites><testcase class="B" name="two"><{tag}/></testcase></testsuites>')
                with self.assertRaisesRegex(ValueError, 'unsuccessful'):
                    module.verify(self.root, 2)

    def test_missing_report(self):
        self.root.joinpath('junit-2.xml').unlink()
        with self.assertRaises(OSError):
            module.verify(self.root, 2)


if __name__ == '__main__':
    unittest.main()
