"""Local data-only tests: no downloaded wheel is installed or imported."""
import contextlib
import importlib.util
import io
import json
from pathlib import Path
import sys
import tempfile
import unittest
from unittest import mock
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
import ml_runtime_contract as contract

spec = importlib.util.spec_from_file_location('provision_runtime', Path(__file__).with_name('provision-ml-runtime.py'))
provision = importlib.util.module_from_spec(spec)
spec.loader.exec_module(provision)
canary_spec = importlib.util.spec_from_file_location('runtime_canary', Path(__file__).with_name('qualify-ml-runtime.py'))
canary = importlib.util.module_from_spec(canary_spec)
canary_spec.loader.exec_module(canary)


class RuntimeContractTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.root = Path(self.temp.name)

    def tearDown(self):
        self.temp.cleanup()

    def wheel(self, name='example', version='1.0', tag='py3-none-any', metadata_extra='', nested=False, machine=None):
        path = self.root / f'{name}-{version}-{tag}.whl'
        prefix = f'{name}-{version}.dist-info/'
        with zipfile.ZipFile(path, 'w') as archive:
            archive.writestr(prefix + 'METADATA', f'Metadata-Version: 2.1\nName: {name}\nVersion: {version}\nRequires-Python: >=3.12,<3.13\n' + metadata_extra)
            archive.writestr(prefix + 'WHEEL', f'Wheel-Version: 1.0\nRoot-Is-Purelib: true\nTag: {tag}\n')
            archive.writestr('example/__init__.py', 'raise AssertionError("wheel payload must not be imported")\n')
            if nested:
                archive.writestr('example/_vendor/another-2.dist-info/METADATA', 'Name: another\nVersion: 2\n')
            if machine is not None:
                header = bytearray(64)
                header[:6] = b'\x7fELF\x02\x01'
                header[18:20] = machine.to_bytes(2, 'little')
                archive.writestr('example/native.so', header)
        return path

    def test_linux_architecture_and_patch_markers_do_not_use_host_defaults(self):
        self.assertTrue(contract.marker_applies(contract.Requirement('alpha; platform_machine == "aarch64" and python_full_version < "3.12.4"')))
        self.assertFalse(contract.marker_applies(contract.Requirement('colorama; sys_platform == "win32"')))
        self.assertFalse(contract.marker_applies(contract.Requirement('xgboost; platform_system != "Linux"')))

    def test_unknown_kernel_marker_refuses_instead_of_inventing_target_metadata(self):
        with self.assertRaisesRegex(ValueError, 'cannot be qualified'):
            contract.marker_applies(contract.Requirement('alpha; platform_release >= "5"'))

    def test_extras_are_explicit(self):
        requirement = contract.Requirement('alpha; extra == "plotting"')
        self.assertFalse(contract.marker_applies(requirement))
        self.assertTrue(contract.marker_applies(requirement, ['plotting']))

    def test_direct_url_is_not_an_implicit_download_permission(self):
        with self.assertRaisesRegex(ValueError, 'Direct URL'):
            contract.marker_applies(contract.Requirement('alpha @ https://example.test/a.whl'))

    def test_valid_wheel_metadata_and_nested_vendor_identity_are_only_read_as_data(self):
        path = self.wheel(nested=True)
        inspected = contract.inspect_wheel(path)
        self.assertEqual(inspected['name'], 'example')
        self.assertEqual(inspected['version'], '1.0')
        self.assertNotIn('example', sys.modules)

    def test_x86_wheel_is_rejected(self):
        with self.assertRaisesRegex(ValueError, 'Wheel tags'):
            contract.inspect_wheel(self.wheel(tag='cp312-cp312-manylinux2014_x86_64'))

    def test_wrong_python_version_is_rejected_before_any_module_import(self):
        path = self.wheel(metadata_extra='Requires-Dist: alpha\n')
        replacement = self.root / 'replacement.whl'
        with zipfile.ZipFile(path) as old, zipfile.ZipFile(replacement, 'w') as new:
            for entry in old.infolist():
                raw = old.read(entry)
                if entry.filename.endswith('/METADATA'):
                    raw = raw.replace(b'>=3.12,<3.13', b'>=3.13')
                new.writestr(entry, raw)
        replacement.replace(path)
        with self.assertRaisesRegex(ValueError, 'Requires-Python'):
            contract.inspect_wheel(path)

    def test_two_top_level_metadata_identities_are_rejected(self):
        path = self.wheel()
        with zipfile.ZipFile(path, 'a') as archive:
            archive.writestr('second-1.dist-info/METADATA', 'Name: second\nVersion: 1\n')
        with self.assertRaisesRegex(ValueError, 'exactly one'):
            contract.inspect_wheel(path)

    def test_filename_metadata_identity_must_match(self):
        path = self.wheel()
        renamed = path.with_name('different-1.0-py3-none-any.whl')
        path.rename(renamed)
        with self.assertRaisesRegex(ValueError, 'identity disagree'):
            contract.inspect_wheel(renamed)

    def test_wheel_traversal_member_is_rejected(self):
        path = self.wheel()
        with zipfile.ZipFile(path, 'a') as archive:
            archive.writestr('../outside.py', 'nothing')
        with self.assertRaisesRegex(ValueError, 'unsafe member'):
            contract.inspect_wheel(path)

    def test_target_platform_native_payload_must_be_aarch64(self):
        valid = self.wheel(tag='py3-none-manylinux2014_aarch64', machine=183)
        self.assertEqual(contract.inspect_wheel(valid)['aarch64_elf_members'], 1)
        valid.unlink()
        with self.assertRaisesRegex(ValueError, 'not AArch64'):
            contract.inspect_wheel(self.wheel(tag='py3-none-manylinux2014_aarch64', machine=62))

    def test_universal_tag_must_not_hide_native_payload(self):
        with self.assertRaisesRegex(ValueError, 'universal'):
            contract.inspect_wheel(self.wheel(machine=183))

    def test_graph_finds_linux_only_transitive_and_ignores_windows_extra(self):
        records = {'root': {'version': '1', 'requires_dist': [
            'linux-child>=2; platform_machine == "aarch64"',
            'windows-child; sys_platform == "win32"', 'plot-child; extra == "plotting"']}}
        required, missing, edges = contract.dependency_graph(records, [contract.Requirement('root==1')])
        self.assertEqual(set(required), {'root', 'linux-child'})
        self.assertEqual(missing, {'linux-child': 'linux-child>=2'})
        self.assertEqual(len(edges), 1)

    def test_graph_detects_incompatible_selected_version(self):
        records = {'root': {'version': '1', 'requires_dist': ['child<2']},
                   'child': {'version': '3', 'requires_dist': []}}
        self.assertIn('child', contract.dependency_graph(records, [contract.Requirement('root==1')])[1])

    def test_known_target_preflight_is_explicit_not_host_assumed(self):
        with mock.patch.object(provision.platform, 'system', return_value='Linux'), \
             mock.patch.object(provision.platform, 'machine', return_value='aarch64'), \
             mock.patch.object(provision.platform, 'libc_ver', return_value=('glibc', '2.28')), \
             mock.patch.object(provision.sys, 'version_info', (3, 12, 3)), \
             mock.patch.object(provision.ctypes.util, 'find_library', return_value='libgomp.so.1'), \
             mock.patch.object(provision.os, 'geteuid', return_value=1000):
            self.assertEqual(provision.actual_target_errors(), [])

    def test_execute_wrong_host_refuses_before_directory_or_subprocess(self):
        destination = self.root / 'new-runtime'
        arguments = ['provision', '--manifest', str(self.root / 'm.json'), '--manifest-sha256', 'a' * 64,
                     '--source', str(self.root), '--destination', str(destination), '--execute']
        with mock.patch.object(sys, 'argv', arguments), \
             mock.patch.object(provision, 'verify_inputs', return_value=({}, self.root / 'lock', self.root / 'wheels')), \
             mock.patch.object(provision, 'actual_target_errors', return_value=['Wrong target']), \
             mock.patch.object(provision.subprocess, 'Popen') as process:
            with self.assertRaisesRegex(RuntimeError, 'Wrong target'):
                provision.main()
            process.assert_not_called()
        self.assertFalse(destination.exists())

    def test_plan_mode_never_installs_or_creates_destination(self):
        destination = self.root / 'new-runtime'
        arguments = ['provision', '--manifest', str(self.root / 'm.json'), '--manifest-sha256', 'a' * 64,
                     '--source', str(self.root), '--destination', str(destination)]
        with mock.patch.object(sys, 'argv', arguments), \
             mock.patch.object(provision, 'verify_inputs', return_value=({}, self.root / 'lock', self.root / 'wheels')), \
             mock.patch.object(provision, 'actual_target_errors', return_value=['Wrong target']), \
             mock.patch.object(provision.subprocess, 'Popen') as process, contextlib.redirect_stdout(io.StringIO()) as out:
            provision.main()
            process.assert_not_called()
        self.assertEqual(json.loads(out.getvalue())['mode'], 'plan_only')
        self.assertFalse(destination.exists())

    def test_child_output_is_bounded_without_executing_downloaded_code(self):
        log = self.root / 'child.log'
        with self.assertRaisesRegex(RuntimeError, 'bounded log'):
            provision.run_bounded([sys.executable, '-I', '-c', 'print("x" * 4096)'],
                                  {'PATH': '/usr/bin:/bin'}, log, timeout=5, maximum=128)
        self.assertLessEqual(log.stat().st_size, 128)

    def test_canary_plan_keeps_missing_sport_explicit_and_never_executes(self):
        data = self.root / 'input.json'
        data.write_text('[]')
        manifest = self.root / 'fixture.json'
        manifest.write_text(json.dumps({'schema': 'offline-runtime-numeric-canary-v1', 'synthetic': True,
                                        'files': {'input.json': provision.digest(data)},
                                        'cases': [{'sport': 'nfl'}]}))
        output = self.root / 'not-created'
        arguments = ['canary', '--fixture-manifest', str(manifest), '--fixture-sha256', provision.digest(manifest),
                     '--output', str(output)]
        with mock.patch.object(sys, 'argv', arguments), mock.patch.object(canary.provision, 'run_bounded') as child, \
             contextlib.redirect_stdout(io.StringIO()) as result:
            self.assertEqual(canary.main(), 0)
            child.assert_not_called()
        report = json.loads(result.getvalue())
        self.assertEqual(report['missing_sports'], ['mlb'])
        self.assertFalse(report['checks_passed'])
        self.assertFalse(output.exists())

    def test_canary_execute_wrong_host_refuses_before_import_or_output(self):
        data = self.root / 'input.json'
        data.write_text('[]')
        manifest = self.root / 'fixture.json'
        manifest.write_text(json.dumps({'schema': 'offline-runtime-numeric-canary-v1', 'synthetic': True,
                                        'files': {'input.json': provision.digest(data)}, 'cases': []}))
        arguments = ['canary', '--fixture-manifest', str(manifest), '--fixture-sha256', provision.digest(manifest),
                     '--output', str(self.root / 'not-created'), '--execute']
        with mock.patch.object(sys, 'argv', arguments), \
             mock.patch.object(canary.provision, 'actual_target_errors', return_value=['Wrong target']), \
             mock.patch.object(canary.provision, 'run_bounded') as child:
            with self.assertRaisesRegex(RuntimeError, 'actual qualified target'):
                canary.main()
            child.assert_not_called()
        self.assertFalse((self.root / 'not-created').exists())


if __name__ == '__main__':
    unittest.main()
