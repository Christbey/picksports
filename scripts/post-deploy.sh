#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PHP_BINARY="${PHP_BINARY:-php8.4}"

cd "${ROOT_DIR}"

echo "Running database migrations..."
"${PHP_BINARY}" artisan migrate --force

echo "Installing release-matched MLB and NFL inference packages..."
bash scripts/install-ml-packages.sh --if-available

echo "Building Laravel production caches..."
"${PHP_BINARY}" artisan optimize:clear
"${PHP_BINARY}" artisan optimize

echo "Restarting queue workers on the optimized release..."
"${PHP_BINARY}" artisan queue:restart

echo "Post-deployment optimization completed."
