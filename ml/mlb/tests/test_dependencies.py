from __future__ import annotations

import importlib.metadata

import pytest

from picksports_mlb_ml.dependencies import dependency_versions


@pytest.mark.parametrize("distribution", ["xgboost", "xgboost-cpu"])
def test_records_installed_xgboost_distribution(monkeypatch, distribution):
    def version(name):
        if name == distribution:
            return "2.1.3"
        if name == "scikit-learn":
            return "1.5.2"
        raise importlib.metadata.PackageNotFoundError(name)

    monkeypatch.setattr(importlib.metadata, "version", version)
    versions = dependency_versions()
    assert versions["xgboost"] == "2.1.3"
    assert versions["scikit-learn"] == "1.5.2"
    assert versions["pandas"] == "unknown"


def test_retains_unknown_when_neither_xgboost_distribution_exists(monkeypatch):
    def missing(name):
        raise importlib.metadata.PackageNotFoundError(name)

    monkeypatch.setattr(importlib.metadata, "version", missing)
    assert dependency_versions()["xgboost"] == "unknown"
