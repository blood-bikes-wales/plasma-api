#!/bin/sh
set -e
cd /app

php artisan config:cache --ansi
php artisan route:cache --ansi
php artisan event:cache --ansi

if [ "$#" -gt 0 ]; then
    exec "$@"
fi

exec docker-php-entrypoint frankenphp run --config /etc/caddy/Caddyfile
