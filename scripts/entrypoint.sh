#!/usr/bin/env bash
set -e

echo "==> Configuring Nginx port for Render..."
PORT="${PORT:-80}"
sed -i "s/listen 80 default_server;/listen ${PORT} default_server;/g" /etc/nginx/http.d/default.conf
sed -i "s/listen \[::\]:80 default_server;/listen \[::\]:${PORT} default_server;/g" /etc/nginx/http.d/default.conf

echo "==> Ensuring directory permissions..."
mkdir -p storage/framework/{sessions,views,cache/data} storage/logs bootstrap/cache
chmod -R 777 storage bootstrap/cache

echo "==> Creating storage link..."
php artisan storage:link --force || true

echo "==> Running Database Migrations..."
php artisan migrate --force

if [ "$RUN_SEEDERS" = "true" ]; then
  echo "==> Seeding database..."
  php artisan db:seed --force || true
fi

echo "==> Caching config, routes and views..."
php artisan config:cache
php artisan route:cache
php artisan view:cache

echo "==> Starting Supervisord (Nginx + PHP-FPM)..."
exec /usr/bin/supervisord -n -c /etc/supervisor/supervisord.conf
