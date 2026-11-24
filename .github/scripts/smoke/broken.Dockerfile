# Variante del smoke (prod-smoke.sh): una versión que no arranca nunca.
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
CMD ["sh", "-c", "echo 'versión rota a propósito (smoke)'; exit 1"]
