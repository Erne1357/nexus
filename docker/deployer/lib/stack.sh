# shellcheck shell=bash
# Operaciones sobre los servicios del stack para un release dado (ADR-0033).

# Levanta lo que no es la app (base de datos, caché, motor de respaldo, Caddy) y espera a que esté sano. Compose
# solo recrea un servicio si su configuración cambió (otra imagen u otro .env); con la misma, no lo toca.
infra_up() {
    local sha=$1
    compose "$sha" up -d --no-deps --wait --wait-timeout 180 postgres valkey
}

edge_up() {
    local sha=$1
    compose "$sha" up -d --no-deps backup caddy
}

# Copia la configuración de Caddy y de los respaldos del release a la carpeta estable que montan. Si cambió el
# Caddyfile y Caddy ya corre, lo recarga sin cortar conexiones.
sync_config() {
    local sha=$1 dir="$RELEASES_DIR/$1/docker" changed=0
    mkdir -p "$NEXUS_CONFIG_DIR/caddy" "$NEXUS_CONFIG_DIR/backup"
    if ! cmp -s "$dir/caddy/Caddyfile" "$NEXUS_CONFIG_DIR/caddy/Caddyfile"; then
        changed=1
    fi
    cp -f "$dir"/caddy/* "$NEXUS_CONFIG_DIR/caddy/"
    cp -f "$dir"/backup/* "$NEXUS_CONFIG_DIR/backup/"
    if [ "$changed" = 1 ] && container_running caddy; then
        log "el Caddyfile cambió: recargando Caddy"
        compose "$sha" exec -T caddy caddy reload --config /etc/caddy/Caddyfile --adapter caddyfile
    fi
}

# ¿La base ya tiene esquema? En el primer arranque no hay nada que respaldar.
database_has_schema() {
    local sha=$1
    compose "$sha" exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -tA' \
        <<<"select to_regclass('public.migrations') is not null;" | grep -qx t
}

# El deployer habla con Caddy y con el motor por la red interna. Si corre con `docker run` (primer arranque), se
# conecta a ella en cuanto existe.
join_backend() {
    local network="${PROJECT}_backend" self
    self=$(hostname)
    docker network inspect "$network" >/dev/null 2>&1 || return 0
    if ! docker inspect --format '{{json .NetworkSettings.Networks}}' "$self" 2>/dev/null | jq -e --arg n "$network" 'has($n)' >/dev/null; then
        docker network connect "$network" "$self" 2>/dev/null || log "aviso: no pude conectarme a $network"
    fi
}

# Plan de migraciones de la imagen nueva (nexus:migration-plan, Core): pendientes, desconocidas, contracciones.
migration_plan() {
    local sha=$1
    compose "$sha" run --rm --no-deps -T --entrypoint php migrate artisan nexus:migration-plan --json
}

# Respaldo previo con el SHA en el nombre (backup.sh predeploy). Imprime el nombre del archivo.
predeploy_backup() {
    local sha=$1 output
    output=$(compose "$sha" run --rm --no-deps -T backup predeploy "$(short "$sha")") ||
        die "$E_MIGRATE" "el respaldo previo falló; no se migra"
    sed -n 's/^PREDEPLOY_FILE=//p' <<<"$output" | tail -n 1
}

run_migrations() {
    local sha=$1
    compose "$sha" run --rm --no-deps -T migrate ||
        die "$E_MIGRATE" "las migraciones de $(short "$sha") fallaron; la versión anterior sigue atendiendo (las migraciones son compatibles con ella)"
}

# Volumen de los assets que sirve Caddy. `compose run -v <nombre>` no le pone el prefijo del proyecto, así que se usa
# el nombre completo; si todavía no existe, se crea con las etiquetas de Compose para que Caddy lo reconozca como suyo.
assets_volume() {
    local volume="${PROJECT}_build-assets"
    if ! docker volume inspect "$volume" >/dev/null 2>&1; then
        docker volume create --label "com.docker.compose.project=$PROJECT" \
            --label com.docker.compose.volume=build-assets "$volume" >/dev/null
    fi
    printf '%s\n' "$volume"
}

# Assets compilados del release al volumen que sirve Caddy. Los nombres llevan hash: los de varios releases
# conviven. La lista de cada release queda en su carpeta para la limpieza.
publish_assets() {
    local sha=$1
    compose "$sha" run --rm --no-deps -T --user 0 --entrypoint sh -v "$(assets_volume):/srv/build" migrate -c \
        'mkdir -p /srv/build/assets && cp -a /app/public/build/assets/. /srv/build/assets/ && cd /app/public/build/assets && ls -1' \
        >"$RELEASES_DIR/$sha/assets.txt"
}

# Borra del volumen los assets que no pertenecen a ningún release que se conserva.
prune_assets() {
    local sha=$1 keep
    keep=$(cat "$RELEASES_DIR"/*/assets.txt 2>/dev/null | sort -u)
    [ -n "$keep" ] || return 0
    printf '%s\n' "$keep" | compose "$sha" run --rm --no-deps -T --user 0 --entrypoint sh -v "$(assets_volume):/srv/build" migrate -c \
        'cat >/tmp/keep && cd /srv/build/assets && for f in *; do grep -qxF "$f" /tmp/keep || rm -f -- "$f"; done'
}

# Espera a que el color responda /health con 200 (ok o degradado solo en lo no crítico) y con el release esperado.
wait_color_ready() {
    local sha=$1 color=$2 deadline body code
    deadline=$((SECONDS + HEALTH_TIMEOUT))
    while [ "$SECONDS" -lt "$deadline" ]; do
        body=$(compose "$sha" exec -T "app-$color" curl -s -o - -w '\n%{http_code}' \
            -H "X-Health-Token: ${HEALTH_TOKEN:-}" http://127.0.0.1:8000/health 2>/dev/null) || body=""
        code=$(tail -n 1 <<<"$body")
        if [ "$code" = 200 ] && [ "$(sed '$d' <<<"$body" | jq -r '.release' 2>/dev/null)" = "$sha" ]; then
            return 0
        fi
        sleep 2
    done
    log "app-$color no quedó sana en ${HEALTH_TIMEOUT}s; último /health: $(sed '$d' <<<"$body" | head -c 600)"
    return 1
}

start_color() {
    local sha=$1 color=$2
    compose "$sha" up -d --no-deps "app-$color"
}

# Saca un color de la rotación de Caddy (su /health pasa a 503), espera a que Caddy lo note y lo detiene con
# apagado ordenado. El contenedor queda detenido, no borrado: volver atrás es encenderlo.
drain_and_stop() {
    local sha=$1 color=$2
    container_running "app-$color" || return 0
    compose "$sha" exec -T "app-$color" touch "$DRAIN_FILE" || true
    sleep "$DRAIN_SECONDS"
    compose "$sha" stop -t 30 "app-$color"
}

# Workers con la imagen nueva, antes de cambiar el tráfico: así solo pasa «job viejo → worker nuevo».
workers_up() {
    local sha=$1
    compose "$sha" up -d --no-deps queue scheduler
}

# El motor guarda sus corridas en memoria: solo se recrea si cambió su imagen y cuando no tiene corridas.
solver_up() {
    local sha=$1 want have deadline runs
    want=$(docker image inspect --format '{{.Id}}' "$(jq -r '.images.solver.ref' "$RELEASES_DIR/$sha/manifest.json")")
    have=$(docker inspect --format '{{.Image}}' "$(container_of solver)" 2>/dev/null || true)
    if [ "$want" = "$have" ] && container_running solver; then
        return 0
    fi
    if container_running solver; then
        deadline=$((SECONDS + SOLVER_WAIT))
        while [ "$SECONDS" -lt "$deadline" ]; do
            runs=$(curl -fsS --max-time 3 http://solver:8000/health 2>/dev/null | jq -r '.runs_in_progress // 0') || runs=0
            [ "$runs" = 0 ] && break
            log "el motor tiene $runs corrida(s) en curso; espero para actualizarlo"
            sleep 10
        done
    fi
    compose "$sha" up -d --no-deps --wait --wait-timeout 120 solver
}

# Verifica por Caddy (la ruta que usan las personas) que atiende el release esperado.
verify_public() {
    local sha=$1 attempt body
    for attempt in 1 2 3 4 5; do
        body=$(curl -sk --max-time 5 --connect-to "${APP_DOMAIN}:443:caddy:443" \
            -H "X-Health-Token: ${HEALTH_TOKEN:-}" "https://${APP_DOMAIN}/health" 2>/dev/null) || body=""
        if [ "$(jq -r '.release' <<<"$body" 2>/dev/null)" = "$sha" ]; then
            return 0
        fi
        log "verificación por Caddy $attempt/5: todavía no atiende $(short "$sha")"
        sleep 2
    done
    return 1
}

# Actualiza el propio deployer desde otro contenedor (si se recreara a sí mismo, moriría a medias).
self_update() {
    local sha=$1 want have
    want=$(jq -r '.images.deployer.ref' "$RELEASES_DIR/$sha/manifest.json")
    have=$(docker inspect --format '{{.Config.Image}}' "$(container_of deployer)" 2>/dev/null || true)
    if [ "$want" = "$have" ] && container_running deployer; then
        return 0
    fi
    log "actualizando el deployer a $want"
    docker run -d --rm --name "$PROJECT-deployer-update-$(short "$sha")" \
        -v /var/run/docker.sock:/var/run/docker.sock -v "$NEXUS_HOME:$NEXUS_HOME" \
        -e NEXUS_HOME -e COMPOSE_PROJECT_NAME="$PROJECT" \
        "$want" self-update "$sha" >/dev/null
}
