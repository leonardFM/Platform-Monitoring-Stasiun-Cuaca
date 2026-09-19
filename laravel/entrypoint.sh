#!/bin/sh
set -e

cd /var/www/html

if [ ! -f .env ]; then
    touch .env
fi

chown -R www-data:www-data storage bootstrap/cache

php artisan optimize || true
php artisan docs:fresh --no-interaction || php artisan l5-swagger:generate || true

case "$1" in
    backend)
        exec supervisord -c /etc/supervisor-backend.conf
        ;;
    worker)
        exec supervisord -c /etc/supervisor-worker.conf
        ;;
    *)
        echo "unknown mode: $1 (expected backend or worker)" >&2
        exit 1
        ;;
esac