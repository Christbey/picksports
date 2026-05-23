#!/bin/bash
set -euo pipefail

APP_DIR="/home/lab/docker/picksports"
TARGET_REF="${1:-staging}"
DEPLOY_BRANCH="deploy"

cd "${APP_DIR}"

echo "Preparing deploy from ref '${TARGET_REF}'..."

if [ ! -d .git ]; then
    echo "Expected a git checkout at ${APP_DIR}, but .git was not found." >&2
    exit 1
fi

git fetch --prune origin

if git show-ref --verify --quiet "refs/remotes/origin/${TARGET_REF}"; then
    TARGET_COMMIT="origin/${TARGET_REF}"
else
    echo "Remote ref 'origin/${TARGET_REF}' does not exist." >&2
    exit 1
fi

echo "Checking out ${TARGET_COMMIT} onto local '${DEPLOY_BRANCH}'..."
git checkout -B "${DEPLOY_BRANCH}" "${TARGET_COMMIT}"
git reset --hard "${TARGET_COMMIT}"

echo "Ensuring containers are running..."
docker compose up -d

echo "Ensuring storage directories exist..."
docker compose exec -T laravel.test sh -lc 'mkdir -p storage/framework/{cache,sessions,views} bootstrap/cache'

echo "Installing Composer dependencies..."
docker compose exec -T laravel.test composer install --no-interaction --optimize-autoloader

echo "Installing NPM dependencies and building assets..."
docker compose exec -T laravel.test npm ci
docker compose exec -T laravel.test npm run build

echo "Running migrations..."
docker compose exec -T laravel.test php artisan migrate --force

echo "Clearing and rebuilding caches..."
docker compose exec -T laravel.test php artisan optimize:clear
docker compose exec -T laravel.test php artisan config:cache
docker compose exec -T laravel.test php artisan route:cache
docker compose exec -T laravel.test php artisan view:cache

echo "Deploy complete for ${TARGET_COMMIT}."
