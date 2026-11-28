import importlib
import importlib.metadata
import pytest

from picksports_mlb_ml import __version__


@pytest.mark.parametrize('module_name', ['pipeline', 'periods'])
def test_producer_records_executed_source_version_when_installed_metadata_is_stale(module_name, monkeypatch):
    module = importlib.import_module('picksports_mlb_ml.' + module_name)
    original = importlib.metadata.version
    monkeypatch.setattr(importlib.metadata, 'version', lambda name: '0.1.0' if name == 'picksports-mlb-ml' else original(name))
    assert module._package_version() == __version__
