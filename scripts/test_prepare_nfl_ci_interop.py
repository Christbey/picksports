import importlib.util
from pathlib import Path
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('nfl_ci_fixture', Path(__file__).with_name('prepare_nfl_ci_interop.py'))
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class NflCiFixtureStructureTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.source = self.root / 'source'
        self.source.write_bytes(b'known synthetic zero 0\n')

    def test_bounded_exact_copy_and_digest(self):
        import hashlib
        output = self.root / 'copy'
        digest = module.copy_bounded(self.source, output, 64, self.source.stat().st_size)
        self.assertEqual(digest, hashlib.sha256(self.source.read_bytes()).hexdigest())
        self.assertEqual(output.read_bytes(), self.source.read_bytes())

    def test_rejects_symlink_and_over_budget_before_copy(self):
        link = self.root / 'link'
        link.symlink_to(self.source)
        for path, maximum in [(link, 64), (self.source, 1)]:
            with self.subTest(path=path):
                output = self.root / 'refused'
                with self.assertRaises(ValueError):
                    module.copy_bounded(path, output, maximum)
                self.assertFalse(output.exists())

    def test_rejects_size_or_digest_mismatch(self):
        for kwargs in [{'expected_bytes': 999}, {'expected_sha256': '0' * 64}]:
            with self.subTest(kwargs=kwargs):
                output = self.root / 'refused'
                with self.assertRaises(ValueError):
                    module.copy_bounded(self.source, output, 64, **kwargs)
                self.assertFalse(output.exists())

    def test_inventory_exact_members_and_individual_budgets(self):
        required = {'manifest-part.json', 'models/native.json'}
        valid = {'manifest-part.json': {'bytes': 1}, 'models/native.json': {'bytes': 2}}
        self.assertEqual(module.inventory_total(valid, required), 3)
        with self.assertRaises(ValueError):
            module.inventory_total({'models/native.json': {'bytes': 2}}, required)
        for value in [-1, True, '1', module.MAX_COMPONENT + 1]:
            with self.subTest(value=value):
                with self.assertRaises(ValueError):
                    module.inventory_total({'models/native.json': {'bytes': value}}, {'models/native.json'})
        with self.assertRaises(ValueError):
            module.inventory_total({'metadata.json': {'bytes': module.MAX_METADATA + 1}}, {'metadata.json'})

    def test_inventory_total_budget_is_admitted_before_copy(self):
        entries = {f'models/native-{i}.json': {'bytes': module.MAX_COMPONENT} for i in range(5)}
        with self.assertRaisesRegex(ValueError, 'total'):
            module.inventory_total(entries, entries)

    def test_refuses_existing_output_without_changing_it(self):
        output = self.root / 'existing'
        output.write_bytes(b'original')
        with self.assertRaises(FileExistsError):
            module.copy_bounded(self.source, output, 64)
        self.assertEqual(output.read_bytes(), b'original')


if __name__ == '__main__':
    unittest.main()
