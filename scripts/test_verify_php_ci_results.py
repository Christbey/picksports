import importlib.util
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

    @staticmethod
    def discovery(ids):
        return '<testSuite xmlns="https://xml.phpunit.de/testSuite"><tests>' + ''.join(f'<testMethod id="{i}"/>' for i in ids) + '</tests></testSuite>'

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
