#!/usr/bin/env python3
"""Convert newline-delimited JSON to real Parquet using PyArrow."""

from __future__ import annotations

import argparse

import pyarrow.json as pajson
import pyarrow.parquet as parquet


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", required=True)
    parser.add_argument("--output", required=True)
    args = parser.parse_args()

    batches = pajson.open_json(
        args.input,
        read_options=pajson.ReadOptions(block_size=8 * 1024 * 1024),
    )
    writer: parquet.ParquetWriter | None = None

    try:
        for batch in batches:
            if writer is None:
                writer = parquet.ParquetWriter(
                    args.output,
                    batch.schema,
                    compression="zstd",
                )
            writer.write_batch(batch)
    finally:
        if writer is not None:
            writer.close()

    if writer is None:
        raise RuntimeError("Refusing to create an empty Parquet file")


if __name__ == "__main__":
    main()
