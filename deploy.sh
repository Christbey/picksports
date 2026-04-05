#!/bin/bash
set -e

cd /home/lab/docker/picksports

echo "Pulling latest changes..."
git pull origin staging

echo "Installing Composer dependencies..."
docker exec picksports-laravel.test-1 composer install --no-interaction --no-dev --optimize-autoloader

echo "Installing NPM dependencies and building assets..."
docker exec picksports-laravel.test-1 npm ci
docker exec picksports-laravel.test-1 npm run build

echo "Running migrations..."
docker exec picksports-laravel.test-1 php artisan migrate --force

echo "Clearing caches..."
docker exec picksports-laravel.test-1 php artisan config:cache
docker exec picksports-laravel.test-1 php artisan route:cache
docker exec picksports-laravel.test-1 php artisan view:cache

echo "Deploy complete!"
