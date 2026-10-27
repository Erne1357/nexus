#!/bin/sh
set -e

# La config depende de variables de entorno del runtime: se cachea al arrancar, no en el build.
php artisan optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
