"""Exercise inventory provenance in an isolated Git repository, without app boot."""
import csv
import hashlib
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest


class InventoryReviewReceiptTest(unittest.TestCase):
    def test_current_review_survives_stale_receipts_but_never_changed_bytes(self):
        script = Path(__file__).resolve().parents[2] / 'scripts/production-readiness-inventory.py'
        with tempfile.TemporaryDirectory(prefix='picksports-inventory-test-') as directory:
            root = Path(directory)
            for folder in ['scripts', 'app', 'config', 'docs/production-readiness']:
                (root / folder).mkdir(parents=True, exist_ok=True)
            shutil.copy2(script, root / 'scripts/production-readiness-inventory.py')
            (root / '.env.example').write_text('')
            subprocess.run(['git', 'init', '--quiet', str(root)], check=True, capture_output=True)
            subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'commit.gpgsign=false',
                            '-c', 'user.name=Inventory fixture', '-c', 'user.email=fixture@example.invalid',
                            'commit', '--allow-empty', '--quiet', '-m', 'Synthetic inventory fixture'],
                           cwd=root, check=True, capture_output=True)
            source = root / 'app/Sample.php'
            source.write_text('<?php // current reviewed bytes\n')
            original = source.read_bytes()
            digest = hashlib.sha256(original).hexdigest()
            reports = root / 'docs/production-readiness'

            def receipt(name, content_hash, status):
                with (reports / name).open('w', newline='') as handle:
                    writer = csv.DictWriter(handle, fieldnames=['path', 'sha256', 'status'])
                    writer.writeheader()
                    writer.writerow({'path': 'app/Sample.php', 'sha256': content_hash, 'status': status})

            def inventory():
                subprocess.run([sys.executable, str(root / 'scripts/production-readiness-inventory.py')],
                               cwd=root, check=True, capture_output=True)
                with (reports / 'file-inventory.csv').open() as handle:
                    rows = list(csv.DictReader(handle))
                self.assertFalse(json.loads((reports / 'inventory-summary.json').read_text())['semantic_review_complete'])
                self.assertNotIn('docs/production-readiness/file-inventory.csv', [row['path'] for row in rows])
                return next(row for row in rows if row['path'] == 'app/Sample.php')

            receipt('a-current-coverage.csv', digest, 'reviewed')
            receipt('z-stale-coverage.csv', '0' * 64, 'reviewed')
            receipt('zz-unreviewed-coverage.csv', digest, 'inventoried')
            row = inventory()
            self.assertEqual('targeted_reviewed', row['status'])
            self.assertEqual('a-current-coverage.csv', row['review_evidence'])
            source.write_text('<?php // changed, not reviewed\n')
            self.assertEqual('inventoried', inventory()['status'])
            source.write_bytes(original)
            receipt('a-current-coverage.csv', digest, 'inventoried')
            self.assertEqual('inventoried', inventory()['status'])
            receipt('a-current-coverage.csv', digest, 'reviewed')
            self.assertEqual('targeted_reviewed', inventory()['status'])
            receipt('a-current-coverage.csv', digest, 'inventoried')
            receipt('b-current-coverage-2026-11-25.csv', digest, 'reviewed')
            row = inventory()
            self.assertEqual('targeted_reviewed', row['status'])
            self.assertEqual('b-current-coverage-2026-11-25.csv', row['review_evidence'])
            source.write_text('<?php // changed after dated review\n')
            self.assertEqual('inventoried', inventory()['status'])


class ConfigurationLookupReceiptTest(unittest.TestCase):
    def test_computed_lookup_requires_current_source_line_and_review_status(self):
        script = Path(__file__).resolve().parents[2] / 'scripts/production-readiness-inventory.py'
        spec = importlib.util.spec_from_file_location('readiness_inventory', script)
        module = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(module)
        with tempfile.TemporaryDirectory(prefix='picksports-config-inventory-') as directory:
            root = Path(directory)
            (root / 'config').mkdir()
            destination = root / 'reports'
            destination.mkdir()
            source = root / 'config/sample.php'
            source.write_text("<?php\n$value = env($key);\nreturn env('LITERAL_KEY');\n")
            digest = hashlib.sha256(source.read_bytes()).hexdigest()

            def receipt(**changes):
                row = {'configuration_file': 'config/sample.php', 'sha256': digest,
                       'lookup_line': '2', 'variable': 'COMPUTED_KEY',
                       'status': 'reviewed_static_keys', 'evidence': 'Reviewed closed literal caller set.'}
                row.update(changes)
                with (destination / 'configuration-static-lookups.csv').open('w', newline='') as handle:
                    writer = csv.DictWriter(handle, fieldnames=list(row))
                    writer.writeheader()
                    writer.writerow(row)

            references, sites = module.configuration_variables(root, destination)
            self.assertEqual(['LITERAL_KEY'], [row['variable'] for row in references])
            self.assertEqual('unresolved', sites[0]['state'])
            receipt()
            references, sites = module.configuration_variables(root, destination)
            self.assertEqual(['COMPUTED_KEY', 'LITERAL_KEY'], [row['variable'] for row in references])
            self.assertEqual('reviewed_static_keys', sites[0]['state'])
            for changes in [{'sha256': '0' * 64}, {'lookup_line': '3'},
                            {'status': 'inventoried'}, {'variable': '../invalid'}, {'evidence': ''}]:
                receipt(**changes)
                references, sites = module.configuration_variables(root, destination)
                self.assertEqual(['LITERAL_KEY'], [row['variable'] for row in references])
                self.assertEqual('unresolved', sites[0]['state'])
            receipt()
            source.write_text(source.read_text() + '// changed source invalidates receipt\n')
            self.assertEqual('unresolved', module.configuration_variables(root, destination)[1][0]['state'])


if __name__ == '__main__':
    unittest.main()
