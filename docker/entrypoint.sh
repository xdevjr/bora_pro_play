#!/usr/bin/env sh
set -eu

cd /var/www/html

role="${1:-app}"

if [ -z "${APP_KEY:-}" ]; then
    echo 'APP_KEY must be defined before the container can start.' >&2
    exit 1
fi

mkdir -p \
    bootstrap/cache \
    database \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs

if [ "${DB_CONNECTION:-sqlite}" = 'sqlite' ]; then
    sqlite_path="${DB_DATABASE:-/var/www/html/database/database.sqlite}"
    mkdir -p "$(dirname "$sqlite_path")"
    touch "$sqlite_path"
fi

chown -R www-data:www-data bootstrap/cache database storage

if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction || true
fi

php artisan package:discover --ansi --no-interaction

if [ "${RUN_MIGRATIONS:-false}" = 'true' ]; then
    php artisan migrate --force --no-interaction
fi

if [ "${LARAVEL_OPTIMIZE:-true}" = 'true' ]; then
    php artisan optimize --no-interaction
fi

case "$role" in
    app)
        exec /usr/bin/supervisord -c /etc/supervisor/supervisord.conf
        ;;
    queue)
        exec php artisan queue:work \
            --verbose \
            --no-interaction \
            --tries="${QUEUE_WORKER_TRIES:-3}" \
            --sleep="${QUEUE_WORKER_SLEEP:-1}" \
            --timeout="${QUEUE_WORKER_TIMEOUT:-90}" \
            --max-time="${QUEUE_WORKER_MAX_TIME:-3600}"
        ;;
    scheduler)
        exec sh -c 'trap "exit 0" TERM INT; while true; do php artisan schedule:run --no-interaction -v; sleep 60 & wait $!; done'
        ;;
    *)
        shift || true
        exec "$role" "$@"
        ;;
esac
