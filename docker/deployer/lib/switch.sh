# shellcheck shell=bash
# Despliegue blue/green sin caídas y vuelta atrás (ADR-0033, runbook «Actualizar» y «Volver atrás»).

# deploy_release <sha> [migrate=1] [action=deploy]
# El release ya está en $RELEASES_DIR/<sha>. Con migrate=0 (rollback) no se migra.
deploy_release() {
    local sha=$1 migrate=${2:-1} action=${3:-deploy}
    local active active_sha target plan pending contracts=0 migrated=0 backup=-

    prepare_release "$sha"
    reconcile_state
    active=$(state_get ACTIVE_COLOR)
    active_sha=$(state_get ACTIVE_SHA)
    if [ -n "$active" ] && ! container_running "app-$active"; then
        log "aviso: el color activo registrado (app-$active) no está corriendo"
    fi
    target=$(other_color "${active:-green}")
    log "$action de $(short "$sha") en app-$target (activo: ${active:-ninguno})"

    sync_backup_config "$sha"
    infra_up "$sha"
    join_backend

    if [ "$migrate" = 1 ]; then
        plan=$(migration_plan "$sha") || die "$E_MIGRATE" "no se pudo leer el plan de migraciones de $(short "$sha")"
        pending=$(jq -r '.pending | length' <<<"$plan")
        if [ "$(jq -r '.pending_contracts | length' <<<"$plan")" -gt 0 ]; then contracts=1; fi
        if [ "$pending" -gt 0 ]; then
            if database_has_schema "$sha"; then
                backup=$(predeploy_backup "$sha")
                log "respaldo previo: ${backup:-?}"
            fi
            log "aplicando $pending migración(es)$([ "$contracts" = 1 ] && echo ', con contracciones')"
            # Se registra antes de migrar: una migración que falla a la mitad puede haber dejado ya una contracción.
            record migrate "$sha" - 1 "$contracts" "$backup" started
            if ! run_migrations "$sha"; then
                record migrate "$sha" - 1 "$contracts" "$backup" failed
                die "$E_MIGRATE" "las migraciones de $(short "$sha") fallaron; $(serving_message "$active")"
            fi
            record migrate "$sha" - 1 "$contracts" "$backup" ok
            migrated=1
        fi
    fi

    solver_up "$sha"
    publish_assets "$sha"
    start_color "$sha" "$target"
    if ! wait_color_ready "$sha" "$target"; then
        compose "$sha" stop -t 10 "app-$target" || true
        record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" failed
        die "$E_UNHEALTHY" "la versión $(short "$sha") no quedó sana; $(serving_message "$active")"
    fi

    # El color nuevo arrancó drenado (Caddy no le manda tráfico): primero los workers con la imagen nueva, para que
    # solo pase «job viejo → worker nuevo»; después entra a la rotación y el viejo sale.
    workers_up "$sha"
    if ! container_running caddy; then
        install_caddy_config "$sha"
    fi
    edge_up "$sha"
    undrain "$sha" "$target"
    if [ -n "$active" ]; then
        drain_and_stop "$sha" "$active"
    fi

    if ! verify_public "$sha"; then
        record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" failed
        if [ -n "$active" ] && [ -n "$active_sha" ]; then
            log "Caddy no atiende $(short "$sha"): vuelvo a $(short "$active_sha")"
            restore_previous_color "$active_sha" "$active" "$target"
            die "$E_VERIFY" "la verificación por Caddy falló después del cambio; se volvió a $(short "$active_sha")"
        fi
        state_set ACTIVE_COLOR "$target"
        state_set ACTIVE_SHA "$sha"
        die "$E_VERIFY" "la verificación por Caddy falló y no hay una versión anterior: queda $(short "$sha") en app-$target (revisa «nexus compose logs caddy»)"
    fi

    state_set ACTIVE_COLOR "$target"
    state_set ACTIVE_SHA "$sha"
    record "$action" "$sha" "$target" "$migrated" "$contracts" "$backup" ok
    log "listo: atiende $(short "$sha") en app-$target"

    apply_caddy_config "$sha"
    prune_assets "$sha"
    prune_releases
    self_update "$sha"
}

# Si el servidor se apagó a mitad de un despliegue, el estado puede no coincidir con lo que corre: con un solo color
# corriendo y no el registrado, ese es el activo (su /health dice qué release atiende).
reconcile_state() {
    local active running=() color release
    active=$(state_get ACTIVE_COLOR)
    for color in blue green; do
        if container_running "app-$color"; then running+=("$color"); fi
    done
    if [ "${#running[@]}" = 1 ] && [ "${running[0]}" != "$active" ]; then
        color=${running[0]}
        release=$(docker exec "$(container_of "app-$color")" sh -c 'curl -s --max-time 5 http://127.0.0.1:8000/health' 2>/dev/null |
            jq -r '.release // empty' 2>/dev/null) || release=""
        log "aviso: el estado decía app-${active:-ninguno}, pero solo corre app-$color (release ${release:-?}); lo tomo como activo"
        state_set ACTIVE_COLOR "$color"
        if [ -n "$release" ] && [ -d "$RELEASES_DIR/$release" ]; then state_set ACTIVE_SHA "$release"; fi
    fi
}

serving_message() {
    if [ -n "$1" ]; then
        echo "la versión anterior sigue atendiendo sin cambios"
    else
        echo "no queda ninguna versión atendiendo (Caddy muestra la página de mantenimiento)"
    fi
}

# Vuelve a poner en rotación el color anterior (sigue con su imagen y quedó drenado). Solo apaga el nuevo si el
# anterior respondió sano: si no, es mejor seguir con el nuevo, que sí pasó su /health.
restore_previous_color() {
    local old_sha=$1 old_color=$2 new_color=$3
    compose "$old_sha" up -d --no-deps "app-$old_color"
    if ! wait_color_ready "$old_sha" "$old_color"; then
        log "aviso: app-$old_color no respondió sano; sigue atendiendo app-$new_color"
        state_set ACTIVE_COLOR "$new_color"
        return 0
    fi
    workers_up "$old_sha"
    solver_up "$old_sha"
    undrain "$old_sha" "$old_color"
    drain_and_stop "$old_sha" "$new_color"
}

# Releases que aplicaron contracciones después de la última vez que $1 atendió: «<sha>\t<respaldo previo>», en orden.
# Cuenta toda migración con contracciones que llegó a correr (aunque el despliegue fallara después). Una restauración
# deshace las que se registraron desde el respaldo que restauró.
contract_crossing() {
    awk -F '\t' -v t="$1" '
        ($2 == "deploy" || $2 == "rollback") && $8 == "ok" && $3 == t { n = 0; split("", seen); next }
        $2 == "restore" {
            for (i = 1; i <= n; i++) if (file[i] == $7) {
                for (j = i; j <= n; j++) delete seen[sha[j] "\t" file[j]]
                n = i - 1
                break
            }
            next
        }
        $5 == "1" && $6 == "1" && !(($3 "\t" $7) in seen) { seen[$3 "\t" $7] = 1; sha[++n] = $3; file[n] = $7 }
        END { for (i = 1; i <= n; i++) print sha[i] "\t" file[i] }' "$STATE_DIR/releases.log"
}

# Niega volver a un release que ya atendió si después se aplicaron contracciones (salvo con restauración).
check_contracts() {
    local target=$1 crossing first
    crossing=$(contract_crossing "$target")
    [ -n "$crossing" ] || return 0
    first=$(head -n 1 <<<"$crossing")
    die "$E_CONTRACT" "el release $(short "${first%%$'\t'*}") quitó columnas o tablas: volver a $(short "$target") exige restaurar el respaldo ${first#*$'\t'} (se pierde lo escrito desde entonces). Repite con «rollback $(short "$target") --restore --yes»"
}

# rollback <destino|previous> [restore=0]
rollback_release() {
    local target=$1 restore=${2:-0} current crossing
    current=$(state_get ACTIVE_SHA)
    [ -n "$current" ] || die "$E_USAGE" "no hay ningún release activo registrado"

    if [ "$target" = previous ]; then
        target=$(successful_releases | grep -vx "$current" | tail -n 1 || true)
        [ -n "$target" ] || die "$E_USAGE" "no hay un release anterior en el historial"
    fi
    target=$(resolve_sha "$target")
    [ "$target" != "$current" ] || die "$E_USAGE" "$(short "$target") ya es el release activo"

    crossing=$(contract_crossing "$target")
    if [ -n "$crossing" ]; then
        [ "$restore" = 1 ] || check_contracts "$target"
        restore_database "$current" "$(head -n 1 <<<"$crossing" | cut -f 2)"
    fi

    deploy_release "$target" 0 rollback
}

# Un SHA (o su inicio) de la carpeta de releases a su forma completa.
resolve_sha() {
    local prefix=$1 match="" dir
    [[ "$prefix" =~ ^[0-9a-z]{4,40}$ ]] || die "$E_USAGE" "«$prefix» no es un sha de release"
    for dir in "$RELEASES_DIR/$prefix"*/; do
        if [ -d "$dir" ]; then match+="$(basename "$dir")"$'\n'; fi
    done
    match=${match%$'\n'}
    [ "$(wc -l <<<"$match")" = 1 ] && [ -n "$match" ] || die "$E_USAGE" "no encuentro un único release que empiece con $prefix en $RELEASES_DIR"
    printf '%s\n' "$match"
}

# Restaura un respaldo previo con la app detenida (Caddy muestra la página de mantenimiento). Primero verifica el
# archivo y restaura en una base aparte; solo si todo salió bien la cambia por la real. La base de antes queda como
# <base>_antes_<fecha> para revisarla y borrarla a mano (runbook).
restore_database() {
    local sha=$1 file=$2 stamp
    [ -n "$file" ] && [ "$file" != - ] || die "$E_CONTRACT" "no hay respaldo previo registrado para restaurar"
    compose "$sha" run --rm --no-deps -T --entrypoint sh backup -c \
        "test -f /backups/predeploy/$file && pg_restore --list /backups/predeploy/$file >/dev/null" ||
        die "$E_CONTRACT" "el respaldo predeploy/$file no existe o no se puede leer; no se tocó nada"
    stamp=$(date '+%Y%m%d%H%M%S')
    log "restaurando $file: la app queda en mantenimiento mientras tanto"
    compose "$sha" stop -t 30 app-blue app-green queue scheduler
    compose "$sha" run --rm --no-deps -T --entrypoint sh -e STAMP="$stamp" -e FILE="$file" backup -c '
        set -e
        tmp="${PGDATABASE}_restaurando"
        dropdb --if-exists "$tmp"
        createdb "$tmp"
        pg_restore --no-owner --exit-on-error --single-transaction -d "$tmp" "/backups/predeploy/$FILE"
        psql -d postgres -v ON_ERROR_STOP=1 -q \
            -c "select pg_terminate_backend(pid) from pg_stat_activity where datname = '"'"'$PGDATABASE'"'"' and pid <> pg_backend_pid()" \
            -c "alter database \"$PGDATABASE\" rename to \"${PGDATABASE}_antes_$STAMP\"" \
            -c "alter database \"$tmp\" rename to \"$PGDATABASE\""' ||
        die "$E_CONTRACT" "la restauración de $file falló; la base real no cambió y la app sigue detenida: «nexus deploy --sha $(short "$sha") --force» la vuelve a levantar"
    # La caché puede guardar datos de después del respaldo.
    compose "$sha" exec -T valkey sh -c 'valkey-cli -a "$REDIS_PASSWORD" --no-auth-warning FLUSHALL' >/dev/null
    record restore "$sha" - 0 0 "$file" ok
    log "listo: la base de antes quedó como ${DB_DATABASE:-nexus}_antes_$stamp (bórrala cuando ya no la necesites)"
    state_set ACTIVE_COLOR ""
}
