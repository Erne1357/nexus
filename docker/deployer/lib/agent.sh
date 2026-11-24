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

# DEPLOY_SOURCE=github (con salida a internet): los Deployments del ambiente `production` que crea deploy.yml. Toma
# el más nuevo que siga en cola, marca como inactivos los anteriores que nadie atendió y publica el resultado.
github_api() {
    local method=$1 path=$2
    shift 2
    curl -fsS -X "$method" --max-time 30 \
        -H "Authorization: Bearer $DEPLOY_GITHUB_TOKEN" \
        -H "Accept: application/vnd.github+json" -H "X-GitHub-Api-Version: 2022-11-28" \
        "${DEPLOY_GITHUB_API:-https://api.github.com}$path" "$@"
}

github_status() {
    local id=$1 state=$2 description=${3:-}
    jq -n --arg state "$state" --arg description "${description:0:140}" \
        --arg url "https://${APP_DOMAIN:-localhost}" \
        '{state: $state, description: $description, environment: "production"}
         + (if $state == "success" then {environment_url: $url} else {} end)' |
        github_api POST "/repos/$GITHUB_REPOSITORY_PATH/deployments/$id/statuses" --data-binary @- >/dev/null
}

agent_tick_github() {
    local deployments id state candidate="" payload code=0 log_file action
    [ -n "${DEPLOY_GITHUB_TOKEN:-}" ] || die "$E_USAGE" "Falta DEPLOY_GITHUB_TOKEN para DEPLOY_SOURCE=github"
    GITHUB_REPOSITORY_PATH=${DEPLOY_REPOSITORY:-Erne1357/nexus}

    deployments=$(github_api GET "/repos/$GITHUB_REPOSITORY_PATH/deployments?environment=production&per_page=10")
    for id in $(jq -r '.[].id' <<<"$deployments"); do
        state=$(github_api GET "/repos/$GITHUB_REPOSITORY_PATH/deployments/$id/statuses?per_page=1" | jq -r '.[0].state // "pending"')
        case "$state" in
            queued | pending)
                if [ -z "$candidate" ]; then
                    candidate=$id
                else
                    log "el Deployment $id quedó atrás de uno más nuevo: inactivo"
                    github_status "$id" inactive "Reemplazado por un despliegue más nuevo."
                fi
                ;;
        esac
    done
    [ -n "$candidate" ] || return 0

    payload=$(mktemp)
    jq --argjson id "$candidate" '.[] | select(.id == $id) | .payload' <<<"$deployments" >"$payload"
    action=$(jq -r '.action // "deploy"' "$payload")
    if [ "$action" = deploy ] && is_paused; then
        rm -f "$payload"
        return 0
    fi

    log "Deployment $candidate de GitHub: $action"
    github_status "$candidate" in_progress "Desplegando en el servidor."
    log_file=$(mktemp)
    if [ "$action" = rollback ]; then
        "$NEXUS_DEPLOY_BIN" rollback "$(jq -r '.target // "previous"' "$payload")" >"$log_file" 2>&1 || code=$?
    else
        "$NEXUS_DEPLOY_BIN" deploy --github-payload "$payload" >"$log_file" 2>&1 || code=$?
    fi
    cat "$log_file" >&2
    if [ "$code" = 0 ]; then
        github_status "$candidate" success "Listo: atiende $(short "$(state_get ACTIVE_SHA)")."
    else
        github_status "$candidate" failure "Código $code: $(grep -m1 'ERROR:' "$log_file" | sed 's/.*ERROR: //')"
    fi
    rm -f "$payload" "$log_file"
}
