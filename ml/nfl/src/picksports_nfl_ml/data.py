from __future__ import annotations

from pathlib import Path

import pandas as pd

from picksports_nfl_ml.hashing import sha256_file
from picksports_nfl_ml.schema import FeatureSchema


class DatasetIntegrityError(RuntimeError):
    """Raised when an input file changes while it is being consumed."""


def load_immutable_dataset(
    path: str | Path,
    schema: FeatureSchema,
    expected_sha256: str | None = None,
) -> tuple[pd.DataFrame, str]:
    resolved = Path(path).expanduser().resolve()
    if not resolved.is_file():
        raise FileNotFoundError(f"Dataset does not exist: {resolved}")

    hash_before = sha256_file(resolved)
    if expected_sha256 is not None and hash_before != expected_sha256.lower():
        raise DatasetIntegrityError(
            f"Dataset SHA-256 mismatch: expected {expected_sha256}, got {hash_before}"
        )

    suffix = resolved.suffix.lower()
    if suffix == ".csv":
        frame = pd.read_csv(resolved, low_memory=False)
    elif suffix in {".parquet", ".pq"}:
        frame = pd.read_parquet(resolved)
    else:
        raise ValueError("Dataset must be an immutable CSV or Parquet export.")

    hash_after = sha256_file(resolved)
    if hash_before != hash_after:
        raise DatasetIntegrityError("Dataset changed while it was being loaded.")

    return schema.validate_training_frame(frame), hash_before
