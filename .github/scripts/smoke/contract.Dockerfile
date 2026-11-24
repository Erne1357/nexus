# Variante del smoke (prod-smoke.sh): la tabla de la expansión y una contracción que le quita una columna.
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
COPY --chown=app:app migrations/ /app/app/Modules/Core/Database/Migrations/
