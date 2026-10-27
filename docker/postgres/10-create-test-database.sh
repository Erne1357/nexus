#!/bin/sh
# Crea la base de pruebas `nexus_test` con el mismo dueño que la base principal.
# La imagen de PostgreSQL solo ejecuta este script cuando el volumen está vacío.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname postgres <<-SQL
    CREATE DATABASE nexus_test OWNER "$POSTGRES_USER";
SQL
