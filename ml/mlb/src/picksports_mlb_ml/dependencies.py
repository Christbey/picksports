from __future__ import annotations

import importlib.metadata


def dependency_versions() -> dict[str, str]:
    versions: dict[str, str] = {}
    for package in (
        "joblib", "numpy", "pandas", "pyarrow", "PyYAML", "scikit-learn", "xgboost",
    ):
        distributions = ("xgboost", "xgboost-cpu") if package == "xgboost" else (package,)
        versions[package] = "unknown"
        for distribution in distributions:
            try:
                versions[package] = importlib.metadata.version(distribution)
                break
            except importlib.metadata.PackageNotFoundError:
                continue
    return versions
