# Variante del smoke (prod-smoke.sh): agrega una tabla (expansión).
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
COPY --chown=app:app migrations/2099_01_01_000000_create_smoke_contract_table.php /app/app/Modules/Core/Database/Migrations/
