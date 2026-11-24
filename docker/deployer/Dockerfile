# syntax=docker/dockerfile:1.7

# Deployer de Nexus (ADR-0033): consigue cada release (un paquete en la carpeta de entrada o, con internet,
# GitHub) y lo despliega en blue/green sin caídas; también vuelve atrás, pausa y reporta. Corre el Docker y el
# Compose del servidor por el socket montado. Imagen base fijada por versión y digest, como todas.
FROM docker:29.10.0-cli@sha256:34507eb5069d8be82c95dd3b6987d4250fd5fee49b82e2509b7d7a7030740d6d

# bash para los scripts, jq para leer JSON (manifiesto, /health, API de GitHub), zstd para las imágenes del
# paquete, coreutils y util-linux por sha256sum y flock con las opciones de GNU.
RUN apk add --no-cache bash curl jq tar zstd coreutils util-linux tzdata

COPY lib/ /usr/local/lib/nexus-deploy/
COPY --chmod=755 bin/ /usr/local/bin/

ENTRYPOINT ["nexus-deploy"]
CMD ["agent"]
