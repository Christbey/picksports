#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PYTHON_BINARY="${ML_PYTHON_BINARY:-/home/forge/picksports-ml/.venv/bin/python}"
OPTIONAL="${1:-}"

if [[ ! -x "${PYTHON_BINARY}" ]]; then
    if [[ "${OPTIONAL}" == "--if-available" ]]; then
        echo "Skipping ML package install; runtime is not present: ${PYTHON_BINARY}"
        exit 0
    fi
    echo "ML Python binary is not executable: ${PYTHON_BINARY}" >&2
    exit 1
fi

for package in mlb nfl; do
    if [[ ! -f "${ROOT_DIR}/ml/${package}/pyproject.toml" ]]; then
        echo "ML package is missing: ${ROOT_DIR}/ml/${package}" >&2
        exit 1
    fi
done

echo "Installing Picksports ML packages from ${ROOT_DIR}..."
"${PYTHON_BINARY}" -m pip install \
    --no-build-isolation \
    --no-deps \
    --force-reinstall \
    "${ROOT_DIR}/ml/mlb" \
    "${ROOT_DIR}/ml/nfl"

"${PYTHON_BINARY}" -c \
    "import picksports_mlb_ml, picksports_nfl_ml; print('ML package imports passed.')"
"${PYTHON_BINARY}" -m pip check

echo "Picksports ML packages are ready."
