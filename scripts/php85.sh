#!/usr/bin/env bash
set -euo pipefail

# PHP_BINARY supports hosts with a versioned or nonstandard binary path.
if [[ -n "${PHP_BINARY:-}" ]]; then
    runtime="${PHP_BINARY}"
elif command -v php8.5 >/dev/null 2>&1; then
    runtime="$(command -v php8.5)"
elif command -v php85 >/dev/null 2>&1; then
    runtime="$(command -v php85)"
else
    runtime="php"
fi

if ! "${runtime}" -r 'exit(PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION === 5 ? 0 : 1);'; then
    echo 'PickSports requires PHP 8.5.x. Set PHP_BINARY to a PHP 8.5 executable.' >&2
    exit 1
fi

exec "${runtime}" "$@"
