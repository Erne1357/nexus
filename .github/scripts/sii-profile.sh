#!/usr/bin/env bash
# Perfila una carpeta de exportaciones del SII (tarea 1.16) con sii-profile.py y deja `_perfil.md` en ella.
# Corre en un contenedor de Python con pandas, sin red, con el usuario local, para que ningún dato salga de la
# máquina y el informe no quede como root. La imagen se construye la primera vez.
#
#   .github/scripts/sii-profile.sh storage/app/imports/sii/2026-11-05
set -euo pipefail

if [[ $# -ne 1 || ! -d "$1" ]]; then
  echo "uso: $0 <carpeta con los CSV del SII>" >&2
  exit 2
fi

dir="$(cd "$1" && pwd)"
scripts="$(cd "$(dirname "$0")" && pwd)"
image="nexus-sii-profile:pandas-2.3.3"

if ! docker image inspect "$image" >/dev/null 2>&1; then
  docker build -q -t "$image" - <<'DOCKERFILE'
FROM python:3.13-slim
RUN pip install --no-cache-dir --root-user-action=ignore pandas==2.3.3
DOCKERFILE
fi

docker run --rm --network none --user "$(id -u):$(id -g)" \
  -v "$dir:/data" -v "$scripts:/scripts:ro" \
  "$image" python -I /scripts/sii-profile.py /data
