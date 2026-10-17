#!/usr/bin/env bash
set -e

echo "==> Configuring Nginx port for Render..."
if [ -n "$PORT" ]; then
  echo "Binding Nginx to PORT $PORT"
  if [ -f /etc/nginx/sites-available/default.conf ]; then
    sed -i "s/listen 80;/listen ${PORT};/g" /etc/nginx/sites-available/default.conf
  fi
  if [ -f /etc/nginx/conf.d/default.conf ]; then
    sed -i "s/listen 80;/listen ${PORT};/g" /etc/nginx/conf.d/default.conf
  fi
fi

echo "==> Ensuring storage directory structure..."
mkdir -p storage/framework/{sessions,views,cache} storage/logs bootstrap/cache
chmod -R 775 storage bootstrap/cache

echo "==> Creating storage link..."
php artisan storage:link --force || true

echo "==> Running Database Migrations..."
php artisan migrate --force

if [ "$RUN_SEEDERS" = "true" ]; then
  echo "==> Seeding database..."
  php artisan db:seed --force
fi

echo "==> Optimizing configuration, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Laravel startup sequence completed successfully!"
