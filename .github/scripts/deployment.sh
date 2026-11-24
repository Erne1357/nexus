#!/usr/bin/env bash
# Crea un Deployment de GitHub en el ambiente `production` con el payload dado y espera a que el deployer del servidor
# lo atienda (ADR-0033). Lo usa deploy.yml. Variables: GH_TOKEN, REPO, SHA, PAUSED.
#
# - success → sale 0.   - failure o error → sale 1 (el workflow falla y GitHub avisa por correo).
# - nadie lo toma en DEPLOY_WAIT_MINUTES (30): aviso, sale 0. El servidor sin internet no consulta GitHub; su release
#   llega por el paquete del artefacto.
set -Eeuo pipefail

payload=$1
wait_minutes=${DEPLOY_WAIT_MINUTES:-30}

if [ "${PAUSED:-false}" = true ]; then
    echo "::notice::Despliegues en pausa (variable DEPLOY_PAUSED): no se crea el Deployment. El paquete sigue en los artefactos."
    exit 0
fi

id=$(jq -n --arg ref "$SHA" --argjson payload "$payload" \
    '{ref: $ref, environment: "production", auto_merge: false, required_contexts: [], payload: $payload,
      description: ("Nexus " + ($payload.action // "deploy"))}' |
    gh api "repos/$REPO/deployments" --input - --jq '.id')
gh api "repos/$REPO/deployments/$id/statuses" -f state=queued -f description="Esperando al servidor." >/dev/null
echo "Deployment $id en cola: $(jq -c . <<<"$payload")"

deadline=$((SECONDS + wait_minutes * 60))
last=""
while [ "$SECONDS" -lt "$deadline" ]; do
    state=$(gh api "repos/$REPO/deployments/$id/statuses?per_page=1" --jq '.[0].state')
    description=$(gh api "repos/$REPO/deployments/$id/statuses?per_page=1" --jq '.[0].description')
    if [ "$state" != "$last" ]; then
        echo "$(date -u +%H:%M:%S) $state: $description"
        last=$state
    fi
    case "$state" in
        success) exit 0 ;;
        failure | error) echo "::error::El servidor no pudo desplegar: $description"; exit 1 ;;
        inactive) echo "::notice::Un despliegue más nuevo reemplazó a este."; exit 0 ;;
    esac
    sleep 30
done
echo "::warning::Nadie tomó el Deployment $id en $wait_minutes min (¿el servidor no tiene internet?). El paquete del release está en los artefactos del workflow."
