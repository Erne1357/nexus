# Variante del smoke (prod-smoke.sh): la misma app con otro release.
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
