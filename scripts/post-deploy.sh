#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_RUNNER="${ROOT_DIR}/scripts/php85.sh"

cd "${ROOT_DIR}"

echo "Running database migrations..."
bash "${PHP_RUNNER}" artisan migrate --force

echo "Installing release-matched MLB and NFL inference packages..."
bash scripts/install-ml-packages.sh --if-available

echo "Building Laravel production caches..."
bash "${PHP_RUNNER}" artisan optimize:clear
bash "${PHP_RUNNER}" artisan optimize

echo "Restarting queue workers on the optimized release..."
bash "${PHP_RUNNER}" artisan queue:restart

echo "Post-deployment optimization completed."
