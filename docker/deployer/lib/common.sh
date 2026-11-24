# shellcheck shell=bash
# shellcheck disable=SC2034 # las variables las usan los demás archivos de lib/
# Valores, registro y Compose del deployer (ADR-0033). Lo cargan los demás archivos de lib/.

NEXUS_HOME=${NEXUS_HOME:-/opt/nexus}
PROJECT=${COMPOSE_PROJECT_NAME:-nexus}
STATE_DIR="$NEXUS_HOME/.deploy"
RELEASES_DIR="$NEXUS_HOME/releases"
# Carpeta estable con la configuración de Caddy y de los respaldos: se actualiza en cada release sin cambiar
# de ruta, para que Compose no recree esos contenedores (compose.prod.yaml).
export NEXUS_CONFIG_DIR="$NEXUS_HOME/config"
export NEXUS_HOME

INBOX=${DEPLOY_INBOX:-$NEXUS_HOME/inbox}
KEEP_RELEASES=${DEPLOY_KEEP_RELEASES:-5}
HEALTH_TIMEOUT=${DEPLOY_HEALTH_TIMEOUT:-180}
DRAIN_SECONDS=${DEPLOY_DRAIN_SECONDS:-6}
SOLVER_WAIT=${DEPLOY_SOLVER_WAIT:-300}
DRAIN_FILE=${NEXUS_DRAIN_FILE:-/tmp/nexus-draining}

# Códigos de salida (runbook, «Si algo falla»).
readonly E_USAGE=2 E_PAUSED=3 E_BUNDLE=4 E_ENV=5 E_UNHEALTHY=6 E_CONTRACT=7 E_VERIFY=8 E_MIGRATE=9 E_LOCKED=10

log() {
    printf '%s deployer: %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*" >&2
}

die() {
    local code=$1
    shift
    log "ERROR: $*"
    exit "$code"
}

# Compose de un release: su carpeta, el .env del servidor y la imagen exacta de cada servicio.
compose() {
    local sha=$1
    shift
    local dir="$RELEASES_DIR/$sha"
    docker compose -p "$PROJECT" --project-directory "$dir" \
        -f "$dir/compose.yaml" -f "$dir/compose.prod.yaml" -f "$dir/compose.release.yaml" "$@"
}

# Lo mismo sin compose.release.yaml: para leer qué imagen pide cada servicio antes de generarlo.
compose_base() {
    local sha=$1
    shift
    local dir="$RELEASES_DIR/$sha"
    docker compose -p "$PROJECT" --project-directory "$dir" -f "$dir/compose.yaml" -f "$dir/compose.prod.yaml" "$@"
}

other_color() {
    if [ "$1" = blue ]; then echo green; else echo blue; fi
}

# Id del contenedor de un servicio del proyecto (corriendo o detenido); vacío si no existe.
container_of() {
    docker ps -aq --filter "label=com.docker.compose.project=$PROJECT" \
        --filter "label=com.docker.compose.service=$1" | awk 'NR == 1'
}

container_running() {
    local id
    id=$(container_of "$1")
    [ -n "$id" ] && [ "$(docker inspect --format '{{.State.Running}}' "$id")" = true ]
}

short() {
    printf '%s' "${1:0:12}"
}
