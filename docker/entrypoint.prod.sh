#!/bin/sh
set -e

# El archivo de drenado (/tmp/nexus-draining, ADR-0033) lo maneja el deployer: un color que apagó vuelve drenado al
# encenderlo, y el deployer lo saca de la rotación o lo pone en ella. No se borra aquí.

# La config depende de variables de entorno del runtime: se cachea al arrancar, no en el build.
php artisan optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
