#!/bin/sh
set -e

# Un contenedor detenido conserva su /tmp: el archivo de drenado que dejó el deployer al apagar este color
# (ADR-0033) no debe sobrevivir a un arranque nuevo, o /health respondería 503 para siempre.
rm -f "${NEXUS_DRAIN_FILE:-/tmp/nexus-draining}"

# La config depende de variables de entorno del runtime: se cachea al arrancar, no en el build.
php artisan optimize

if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

exec "$@"
