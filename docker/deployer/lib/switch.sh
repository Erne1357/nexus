# shellcheck shell=bash
# Despliegue blue/green sin caídas y vuelta atrás (ADR-0033, runbook «Actualizar» y «Volver atrás»).

# deploy_release <sha> [migrate=1] [action=deploy]
# El release ya está en $RELEASES_DIR/<sha>. Con migrate=0 (rollback) no se migra.
deploy_release() {
    local sha=$1 migrate=${2:-1} action=${3:-deploy}
    local active target plan pending contracts=0 migrated=0 backup=-

    prepare_release "$sha"
    active=$(state_get ACTIVE_COLOR)
    if [ -n "$active" ] && ! container_running "app-$active"; then
        log "aviso: el color activo registrado (app-$active) no está corriendo"
    fi
    target=$(other_color "${active:-green}")
    log "$action de $(short "$sha") en app-$target (activo: ${active:-ninguno})"

    sync_config "$sha"
    infra_up "$sha"
    join_backend

    if [ "$migrate" = 1 ]; then
        plan=$(migration_plan "$sha") || die "$E_MIGRATE" "no se pudo leer el plan de migraciones de $(short "$sha")"
        pending=$(jq -r '.pending | length' <<<"$plan")
        [ "$(jq -r '.pending_contracts | length' <<<"$plan")" -gt 0 ] && contracts=1
        if [ "$pending" -gt 0 ]; then
            if database_has_schema "$sha"; then
                backup=$(predeploy_backup "$sha")
                log "respaldo previo: ${backup:-?}"
            fi
            log "aplicando $pending migración(es)$([ "$contracts" = 1 ] && echo ', con contracciones')"
            run_migrations "$sha"
            migrated=1
        fi
    fi

    solver_up "$sha"
    publish_assets "$sha"
    start_color "$sha" "$target"
    if ! wait_color_ready "$sha" "$target"; then
        compose "$sha" stop -t 10 "app-$target" || true
        record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" failed
        die "$E_UNHEALTHY" "la versión $(short "$sha") no quedó sana; la anterior sigue atendiendo sin cambios"
    fi

    workers_up "$sha"
    edge_up "$sha"
    if [ -n "$active" ]; then
        drain_and_stop "$sha" "$active"
    fi

    if ! verify_public "$sha"; then
        log "Caddy no atiende $(short "$sha"): vuelvo a la versión anterior"
        record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" failed
        if [ -n "$active" ]; then
            restore_previous_color "$(state_get ACTIVE_SHA)" "$active" "$target"
        fi
        die "$E_VERIFY" "la verificación por Caddy falló después del cambio; se volvió a $(short "$(state_get ACTIVE_SHA)")"
    fi

    state_set ACTIVE_COLOR "$target"
    state_set ACTIVE_SHA "$sha"
    record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" ok
    log "listo: atiende $(short "$sha") en app-$target"

    prune_assets "$sha"
    prune_releases
    self_update "$sha"
}

# Vuelve a encender el color anterior (sigue con su imagen) y apaga el nuevo.
restore_previous_color() {
    local old_sha=$1 old_color=$2 new_color=$3
    compose "$old_sha" up -d --no-deps "app-$old_color"
    wait_color_ready "$old_sha" "$old_color" || log "aviso: app-$old_color tampoco respondió sana"
    workers_up "$old_sha"
    drain_and_stop "$old_sha" "$new_color"
}

# rollback <destino|previous> [restore=0]
rollback_release() {
    local target=$1 restore=${2:-0} current crossing restore_file
    current=$(state_get ACTIVE_SHA)
    [ -n "$current" ] || die "$E_USAGE" "no hay ningún release activo registrado"

    if [ "$target" = previous ]; then
        target=$(successful_releases | grep -vx "$current" | tail -n 1)
        [ -n "$target" ] || die "$E_USAGE" "no hay un release anterior en el historial"
    fi
    target=$(resolve_sha "$target")
    [ "$target" != "$current" ] || die "$E_USAGE" "$(short "$target") ya es el release activo"

    # Releases con contracciones desplegados después del último despliegue del destino.
    crossing=$(awk -F '\t' -v t="$target" '
        $8 == "ok" && $3 == t { split("", hits); n = 0; next }
        $8 == "ok" && $2 == "deploy" && $6 == "1" { hits[++n] = $3 "\t" $7 }
        END { for (i = 1; i <= n; i++) print hits[i] }' "$STATE_DIR/releases.log")

    if [ -n "$crossing" ]; then
        restore_file=$(head -n 1 <<<"$crossing" | cut -f 2)
        if [ "$restore" != 1 ]; then
            die "$E_CONTRACT" "el release $(short "$(head -n 1 <<<"$crossing" | cut -f 1)") quitó columnas o tablas: volver a $(short "$target") exige restaurar el respaldo $restore_file (se pierde lo escrito desde entonces). Repite con --restore --yes"
        fi
        restore_database "$current" "$restore_file"
    fi

    deploy_release "$target" 0 rollback
}

# Un SHA corto del historial o de la carpeta de releases a su forma completa.
resolve_sha() {
    local match
    # Sin `find -printf`: la imagen usa el find de busybox.
    match=$(ls -1 "$RELEASES_DIR" | grep -E "^${1//[^A-Za-z0-9]/}" || true)
    [ "$(wc -l <<<"$match")" = 1 ] && [ -n "$match" ] || die "$E_USAGE" "no encuentro un único release que empiece con $1 en $RELEASES_DIR"
    printf '%s\n' "$match"
}

# Restaura un respaldo previo sobre la base real, con la app detenida (Caddy muestra la página de mantenimiento).
restore_database() {
    local sha=$1 file=$2
    [ -n "$file" ] && [ "$file" != - ] || die "$E_CONTRACT" "no hay respaldo previo registrado para restaurar"
    log "restaurando $file: la app queda en mantenimiento mientras tanto"
    compose "$sha" stop -t 30 app-blue app-green queue scheduler
    compose "$sha" run --rm --no-deps -T --entrypoint sh backup -c \
        "dropdb --force \"\$PGDATABASE\" && createdb \"\$PGDATABASE\" && pg_restore --no-owner --exit-on-error --single-transaction -d \"\$PGDATABASE\" /backups/predeploy/$file" ||
        die "$E_CONTRACT" "la restauración de $file falló; la app sigue detenida (runbook, «Restaurar un respaldo»)"
    # La caché puede guardar datos de después del respaldo.
    compose "$sha" exec -T valkey sh -c 'valkey-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL' >/dev/null
    state_set ACTIVE_COLOR ""
}
