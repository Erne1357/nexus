# shellcheck shell=bash
# Estado del deployer en $NEXUS_HOME/.deploy (ADR-0033):
#   state         ACTIVE_COLOR=blue|green y ACTIVE_SHA=<sha>
#   releases.log  historial, una línea por operación, separado por tabuladores:
#                 fecha  acción(deploy|rollback)  sha  color  migró(0|1)  contracciones(0|1)  respaldo  resultado
#   paused        existe mientras el despliegue está en pausa; su contenido es el motivo
#   deploy.lock   candado: un despliegue o rollback a la vez

state_init() {
    mkdir -p "$STATE_DIR" "$RELEASES_DIR" "$NEXUS_CONFIG_DIR" "$INBOX"
    touch "$STATE_DIR/state" "$STATE_DIR/releases.log"
}

state_get() {
    sed -n "s/^$1=//p" "$STATE_DIR/state" | tail -n 1
}

state_set() {
    local key=$1 value=$2 tmp
    tmp=$(mktemp "$STATE_DIR/.state.XXXXXX")
    grep -v "^$key=" "$STATE_DIR/state" >"$tmp" || true
    printf '%s=%s\n' "$key" "$value" >>"$tmp"
    mv "$tmp" "$STATE_DIR/state"
}

# record <acción> <sha> <color> <migró> <contracciones> <respaldo> <resultado>
record() {
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$(date '+%Y-%m-%dT%H:%M:%S%z')" "$@" >>"$STATE_DIR/releases.log"
}

# Toma el candado o espera hasta 10 min a que termine la operación en curso.
take_lock() {
    exec 9>"$STATE_DIR/deploy.lock"
    flock -w 600 9 || die "$E_LOCKED" "otro despliegue sigue en curso desde hace más de 10 minutos"
}

is_paused() {
    [ -f "$STATE_DIR/paused" ]
}

pause_deploys() {
    printf '%s\n' "${*:-sin motivo}" >"$STATE_DIR/paused"
    log "despliegues en pausa: ${*:-sin motivo}. Lo que llegue espera hasta «resume»."
}

resume_deploys() {
    rm -f "$STATE_DIR/paused"
    log "despliegues reanudados"
}

# Releases desplegados con éxito, del más viejo al más nuevo, sin repetir (el último despliegue de cada uno).
successful_releases() {
    awk -F '\t' '$8 == "ok" { order[$3] = NR; } END { for (s in order) print order[s] "\t" s }' "$STATE_DIR/releases.log" |
        sort -n | cut -f 2
}

# Borra los releases que ya no están entre los últimos $KEEP_RELEASES ni activos, con sus imágenes propias.
prune_releases() {
    local keep active sha
    active=$(state_get ACTIVE_SHA)
    keep=$(successful_releases | tail -n "$KEEP_RELEASES")
    for dir in "$RELEASES_DIR"/*/; do
        [ -d "$dir" ] || continue
        sha=$(basename "$dir")
        if [ "$sha" = "$active" ] || grep -qx "$sha" <<<"$keep"; then
            continue
        fi
        log "limpiando el release viejo $(short "$sha")"
        jq -r '.images[].ref' "$dir/manifest.json" 2>/dev/null | while read -r ref; do
            docker image rm "$ref" >/dev/null 2>&1 || true
        done
        rm -rf -- "$dir"
    done
}
