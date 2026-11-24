# shellcheck shell=bash
# El agente del deployer (servicio `deployer`, comando por omisión): cada DEPLOY_POLL_SECONDS busca qué
# desplegar. DEPLOY_SOURCE=bundle (hoy, el servidor sin internet): la carpeta de entrada.

POLL_SECONDS=${DEPLOY_POLL_SECONDS:-60}

agent_loop() {
    local source=${DEPLOY_SOURCE:-bundle}
    state_init
    mkdir -p "$INBOX/hechos" "$INBOX/fallidos" "$INBOX/omitidos"
    log "agente iniciado: fuente «$source», revisa cada ${POLL_SECONDS}s"
    while true; do
        case "$source" in
            bundle) agent_tick_bundle || log "aviso: la revisión de $INBOX falló; se reintenta" ;;
            github) agent_tick_github || log "aviso: la consulta a GitHub falló; se reintenta" ;;
            *) die "$E_USAGE" "DEPLOY_SOURCE debe ser «bundle» o «github» (recibí «$source»)" ;;
        esac
        sleep "$POLL_SECONDS"
    done
}

# Un paquete se toma solo cuando ya llegó completo: tiene su .sha256 y su tamaño no cambió desde la revisión
# anterior (una copia lenta por la red sigue creciendo). Los *.part se ignoran.
agent_tick_bundle() {
    local sizes="$STATE_DIR/inbox.sizes" now newest="" newest_at="" file at request target
    now=$(mktemp)

    # Pedidos de vuelta atrás: un archivo vacío «rollback-<sha>» o «rollback-previous».
    for request in "$INBOX"/rollback-*; do
        [ -e "$request" ] || continue
        target=${request##*/rollback-}
        log "pedido de vuelta atrás a $target"
        agent_run "$INBOX/$(basename "$request")" rollback "$target"
    done

    is_paused && {
        rm -f "$now"
        return 0
    }

    for file in "$INBOX"/nexus-*.tar; do
        [ -f "$file" ] && [ -f "$file.sha256" ] || continue
        printf '%s\t%s\n' "$file" "$(stat -c %s "$file")" >>"$now"
        grep -qxF "$(tail -n 1 "$now")" "$sizes" 2>/dev/null || continue
        at=$(tar --occurrence=1 -xOf "$file" manifest.json 2>/dev/null | jq -r '.created_at // empty') || at=""
        [ -n "$at" ] || at=$(date -r "$file" '+%Y-%m-%dT%H:%M:%SZ')
        if [ -z "$newest" ] || [[ "$at" > "$newest_at" ]]; then
            [ -n "$newest" ] && skip_bundle "$newest"
            newest=$file
            newest_at=$at
        else
            skip_bundle "$file"
        fi
    done
    mv "$now" "$sizes"

    [ -n "$newest" ] || return 0
    log "paquete nuevo: $(basename "$newest")"
    agent_run "$newest" deploy --bundle "$newest"
}

skip_bundle() {
    log "omito $(basename "$1"): hay un paquete más nuevo en la carpeta"
    mv -f -- "$1" "$1.sha256" "$INBOX/omitidos/"
}

# agent_run <archivo del pedido> <subcomando> [args]: corre el subcomando en otro proceso (un error no tumba al
# agente) y archiva el pedido en hechos/ o fallidos/ con su registro.
agent_run() {
    local request=$1 name log_file code=0
    shift
    name=$(basename "$request")
    log_file=$(mktemp)
    "$NEXUS_DEPLOY_BIN" "$@" >"$log_file" 2>&1 || code=$?
    cat "$log_file" >&2
    if [ "$code" = 0 ]; then
        mv -f -- "$log_file" "$INBOX/hechos/$name.log"
        mv -f -- "$request" "$INBOX/hechos/"
        [ -f "$request.sha256" ] && mv -f -- "$request.sha256" "$INBOX/hechos/"
    else
        log "falló $name (código $code); queda en fallidos/ con su registro"
        mv -f -- "$log_file" "$INBOX/fallidos/$name.log"
        mv -f -- "$request" "$INBOX/fallidos/"
        [ -f "$request.sha256" ] && mv -f -- "$request.sha256" "$INBOX/fallidos/"
    fi
    return 0
}

# DEPLOY_SOURCE=github: tarea 1.31 (#790).
agent_tick_github() {
    die "$E_USAGE" "DEPLOY_SOURCE=github todavía no está disponible en esta versión del deployer"
}
