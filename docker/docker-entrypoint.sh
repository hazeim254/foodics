#!/bin/bash
set -e

if [ -z "${APP_KEY}" ]; then
    php artisan key:generate --force
fi

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
