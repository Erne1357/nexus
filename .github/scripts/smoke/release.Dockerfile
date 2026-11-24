# Variante del smoke (prod-smoke.sh): la misma app con otro release y un asset propio, para probar que los assets de
# un release siguen disponibles después de cambiar de color.
ARG BASE
FROM ${BASE}
ARG RELEASE
ENV NEXUS_RELEASE=${RELEASE}
RUN echo "/* ${RELEASE} */" > "/app/public/build/assets/smoke-${RELEASE}.js"
