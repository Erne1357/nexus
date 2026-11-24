# Variante del smoke (prod-smoke.sh): trae la contracción, la migra y luego no arranca.
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
COPY --chown=app:app migrations/ /app/app/Modules/Core/Database/Migrations/
CMD ["sh", "-c", "echo 'versión rota a propósito (smoke)'; exit 1"]
