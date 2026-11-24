#!/usr/bin/env bash
# Smoke del stack de producción con despliegues sin caídas (ADR-0033, tarea 1.31). Levanta el stack completo con
# el deployer real, en un proyecto aparte, y recorre los escenarios del runbook con tráfico continuo por Caddy:
#
#   1 primer arranque desde una base vacía      5 paquete alterado (se rechaza)
#   2 despliegue sin caídas (cambio de color)   6 vuelta atrás sin caídas
#   3 versión rota (producción no cambia)       7 contracción: rollback negado y con --restore
#   4 .env sin una variable nueva (se rechaza)  8 reinicio del daemon de Docker (solo con --restart-daemon)
#
# Uso: prod-smoke.sh --bundle <nexus-<sha>.tar> [--project <nombre>] [--home <carpeta>] [--restart-daemon] [--keep]
# El paquete sale de nexus-bundle (docker/deployer/bin) con las imágenes ya construidas. En local, usa otro nombre
# de proyecto y deja libres los puertos 18080 y 18443; nunca toca el proyecto `nexus` de desarrollo.
set -Eeuo pipefail

bundle="" project=nexus-smoke home="" restart_daemon=0 keep=0
while [ $# -gt 0 ]; do
    case "$1" in
        --bundle) bundle=$(readlink -f "$2"); shift 2 ;;
        --project) project=$2; shift 2 ;;
        --home) home=$2; shift 2 ;;
        --restart-daemon) restart_daemon=1; shift ;;
        --keep) keep=1; shift ;;
        *) echo "opción desconocida: $1" >&2; exit 2 ;;
    esac
done
[ -f "$bundle" ] || { echo "uso: prod-smoke.sh --bundle <paquete.tar>" >&2; exit 2; }
[ "$project" != nexus ] || { echo "el smoke nunca usa el proyecto «nexus»" >&2; exit 2; }

repo=$(cd "$(dirname "$0")/../.." && pwd)
smoke_dir="$repo/.github/scripts/smoke"
home=${home:-${RUNNER_TEMP:-/tmp}/$project}
home=$(mkdir -p "$home" && cd "$home" && pwd)
domain=nexus-smoke.test
https_port=18443
results=()
failures=0
started=$SECONDS

say() { printf '\n\033[1m== %s\033[0m\n' "$*"; }
pass() { results+=("ok    $1"); printf '   ok: %s\n' "$1"; }
fail() { results+=("FALLA $1"); printf '   FALLA: %s\n' "$1"; failures=$((failures + 1)); }

cleanup() {
    stop_traffic 2>/dev/null || true
    if [ "$keep" = 0 ]; then
        docker ps -aq --filter "label=com.docker.compose.project=$project" | xargs -r docker rm -f >/dev/null
        docker ps -aq --filter "name=^$project-deployer" | xargs -r docker rm -f >/dev/null
        docker volume ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker volume rm >/dev/null
        docker network ls -q --filter "label=com.docker.compose.project=$project" | xargs -r docker network rm >/dev/null
        rm -rf -- "$home" 2>/dev/null || sudo rm -rf -- "$home" 2>/dev/null || true
    fi
    printf '\nEscenarios del smoke (%ss):\n' "$((SECONDS - started))"
    printf '  %s\n' "${results[@]}"
}
trap cleanup EXIT

# El deployer solo ve $home (lo monta en la misma ruta): el paquete de entrada se copia ahí.
mkdir -p "$home/bundles"
cp "$bundle" "$bundle.sha256" "$home/bundles/"
bundle="$home/bundles/$(basename "$bundle")"

manifest() { tar --occurrence=1 -xOf "$bundle" manifest.json | jq -r "$1"; }
sha_a=$(manifest .sha)
app_a=$(manifest .images.app.ref)
solver_ref=$(manifest .images.solver.ref)
deployer_ref=$(manifest .images.deployer.ref)

# --- Entorno: el .env sale de la plantilla de producción con secretos aleatorios -------------------------
mkdir -p "$home/inbox" "$home/backups" "$home/fotos"
secret() { head -c "${1:-24}" /dev/urandom | od -An -tx1 | tr -d ' \n'; }
cp "$repo/.env.production.example" "$home/.env"
set_env() {
    if grep -q "^$1=" "$home/.env"; then
        sed -i "s|^$1=.*|$1=$2|" "$home/.env"
    else
        printf '%s=%s\n' "$1" "$2" >>"$home/.env"
    fi
}
set_env APP_DOMAIN "$domain"
set_env ACME_EMAIL smoke@example.edu.mx
set_env APP_KEY "base64:$(head -c 32 /dev/urandom | base64)"
set_env DB_PASSWORD "$(secret)"
set_env REDIS_PASSWORD "$(secret)"
set_env SOLVER_TOKEN "$(secret 32)"
set_env ADMIN_INITIAL_PASSWORD "$(secret 12)"
set_env HEALTH_TOKEN "$(secret 24)"
set_env MAIL_MAILER log
set_env NEXUS_HOME "$home"
set_env DEPLOY_INBOX "$home/inbox"
set_env DEPLOY_POLL_SECONDS 3
set_env DEPLOY_HEALTH_TIMEOUT 120
set_env BACKUP_DIR "$home/backups"
set_env BACKUP_UID "$(id -u)"
set_env BACKUP_GID "$(id -g)"
set_env NEXUS_STUDENT_PHOTOS_DIR "$home/fotos"
set_env HTTP_PORT 18080
set_env HTTPS_PORT "$https_port"
set_env CADDY_GLOBAL_OPTIONS local_certs
chmod 600 "$home/.env"
health_token=$(sed -n 's/^HEALTH_TOKEN=//p' "$home/.env")

deployer() {
    docker run --rm --name "$project-deployer-run-$RANDOM" \
        -v /var/run/docker.sock:/var/run/docker.sock -v "$home:$home" \
        --env-file "$home/.env" -e NEXUS_HOME="$home" -e COMPOSE_PROJECT_NAME="$project" \
        "$deployer_ref" "$@"
}

# expect_code <código esperado> <descripción> -- <comando del deployer…>
expect_code() {
    local want=$1 what=$2 code=0
    shift 3
    deployer "$@" || code=$?
    if [ "$code" = "$want" ]; then pass "$what (código $code)"; else fail "$what: esperaba código $want, salió $code"; fi
}

public_health() {
    curl -sk --max-time 5 --resolve "$domain:$https_port:127.0.0.1" -H "X-Health-Token: $health_token" \
        "https://$domain:$https_port/health"
}

expect_release() {
    local want=$1 what=$2 body
    body=$(public_health || true)
    if [ "$(jq -r .release <<<"$body" 2>/dev/null)" = "$want" ] &&
        [[ "$(jq -r .status <<<"$body" 2>/dev/null)" =~ ^(ok|degraded)$ ]]; then
        pass "$what: atiende $want ($(jq -r .status <<<"$body"))"
    else
        fail "$what: esperaba $want, /health dijo: $(head -c 400 <<<"$body")"
    fi
}

# --- Tráfico continuo: /login, un asset de /build y /health cada ~100 ms --------------------------------
traffic_log="$home/traffic.log"
traffic_pid=""
start_traffic() {
    local asset
    asset=$(curl -sk --resolve "$domain:$https_port:127.0.0.1" "https://$domain:$https_port/login" |
        grep -o '/build/assets/[^"]*\.js' | head -n 1)
    : >"$traffic_log"
    (
        while true; do
            for path in /login "$asset" /health; do
                printf '%s %s\n' "$(curl -sk -o /dev/null --max-time 10 -w '%{http_code}' \
                    --resolve "$domain:$https_port:127.0.0.1" "https://$domain:$https_port$path")" "$path" >>"$traffic_log"
            done
            sleep 0.1
        done
    ) &
    traffic_pid=$!
}
stop_traffic() {
    [ -n "$traffic_pid" ] || return 0
    kill "$traffic_pid" 2>/dev/null || true
    wait "$traffic_pid" 2>/dev/null || true
    traffic_pid=""
}
# Toda respuesta 5xx o sin conexión (000) durante el escenario es una caída.
expect_no_downtime() {
    local what=$1 total bad
    stop_traffic
    total=$(wc -l <"$traffic_log")
    bad=$(grep -cvE '^(2|3)[0-9][0-9] ' "$traffic_log" || true)
    if [ "$total" -gt 20 ] && [ "$bad" = 0 ]; then
        pass "$what: $total peticiones sin ninguna falla"
    else
        fail "$what: $bad de $total peticiones fallaron ($(grep -vE '^(2|3)[0-9][0-9] ' "$traffic_log" | sort | uniq -c | head -5 | tr '\n' ';'))"
    fi
}

# --- Variantes del release A, para tener versiones distintas sin reconstruir la app ---------------------
# variant <sha> <Dockerfile de smoke/> [carpeta del repo]: imagen FROM la app de A y su paquete en $home/bundles.
variant() {
    local sha=$1 dockerfile=$2 repo_dir=${3:-$repo}
    docker build -q -t "${app_a%:*}:sha-$sha" --build-arg "BASE=$app_a" --build-arg "RELEASE=$sha" \
        -f "$smoke_dir/$dockerfile" "$smoke_dir" >/dev/null
    "$repo/docker/deployer/bin/nexus-bundle" --sha "$sha" --app "${app_a%:*}:sha-$sha" --solver "$solver_ref" \
        --deployer "$deployer_ref" --repo "$repo_dir" --out "$home/bundles" 2>/dev/null
    printf '%s\n' "$home/bundles/nexus-$sha.tar"
}

# =========================================================================================================
say "1. Primer arranque de $sha_a desde una base vacía"
expect_code 0 "bootstrap" -- deploy --bundle "$bundle"
docker exec "$(docker ps -q --filter "label=com.docker.compose.project=$project" --filter label=com.docker.compose.service=app-blue)" \
    php artisan db:seed --force --no-interaction >/dev/null && pass "db:seed" || fail "db:seed"
expect_release "$sha_a" "bootstrap"

say "2. Despliegue sin caídas de B"
bundle_b=$(variant smokeb000001 release.Dockerfile)
start_traffic
expect_code 0 "deploy B" -- deploy --bundle "$bundle_b"
expect_no_downtime "deploy B"
expect_release smokeb000001 "deploy B"

say "3. Una versión que no arranca no toca producción"
bundle_broken=$(variant smokex000003 broken.Dockerfile)
start_traffic
expect_code 6 "versión rota" -- deploy --bundle "$bundle_broken"
expect_no_downtime "versión rota"
expect_release smokeb000001 "tras la versión rota"

say "4. Un release que pide una variable nueva no se despliega con el .env viejo"
env_repo="$home/repo-env"
mkdir -p "$env_repo" && tar -C "$repo" -cf - compose.yaml compose.prod.yaml docker .env.production.example | tar -C "$env_repo" -xf -
printf '\n# Variable nueva (smoke)\nNUEVA_VARIABLE=\n' >>"$env_repo/.env.production.example"
bundle_env=$(variant smokee000004 release.Dockerfile "$env_repo")
expect_code 5 ".env incompleto" -- deploy --bundle "$bundle_env"
expect_release smokeb000001 "tras el .env incompleto"

say "5. Un paquete alterado se rechaza"
cp "$bundle_b" "$home/bundles/nexus-smokeb000001-alterado.tar"
sed "s/nexus-smokeb000001.tar/nexus-smokeb000001-alterado.tar/" "$bundle_b.sha256" >"$home/bundles/nexus-smokeb000001-alterado.tar.sha256"
printf 'X' | dd of="$home/bundles/nexus-smokeb000001-alterado.tar" bs=1 seek=4096 conv=notrunc status=none
expect_code 4 "paquete alterado" -- deploy --bundle "$home/bundles/nexus-smokeb000001-alterado.tar"

say "6. Vuelta atrás sin caídas"
start_traffic
expect_code 0 "rollback a A" -- rollback previous
expect_no_downtime "rollback a A"
expect_release "$sha_a" "rollback a A"

say "7. Contracción: volver atrás exige restaurar el respaldo"
bundle_c=$(variant smokec000007 expand.Dockerfile)
expect_code 0 "deploy C (crea una tabla)" -- deploy --bundle "$bundle_c"
bundle_d=$(variant smoked000007 contract.Dockerfile)
expect_code 0 "deploy D (contracción)" -- deploy --bundle "$bundle_d"
expect_code 7 "rollback a C sin --restore" -- rollback smokec000007
expect_release smoked000007 "tras el rollback negado"
expect_code 0 "rollback a C con --restore --yes" -- rollback smokec000007 --restore --yes
expect_release smokec000007 "rollback con restauración"

if [ "$restart_daemon" = 1 ]; then
    say "8. Reinicio del daemon de Docker"
    sudo systemctl restart docker
    sleep 20
    for _ in $(seq 1 30); do
        [[ "$(public_health | jq -r .status 2>/dev/null)" =~ ^(ok|degraded)$ ]] && break
        sleep 3
    done
    expect_release smokec000007 "tras reiniciar Docker"
    active=$(deployer status --json | jq -r .active_color)
    other=$([ "$active" = blue ] && echo green || echo blue)
    if [ -z "$(docker ps -q --filter "label=com.docker.compose.project=$project" --filter "label=com.docker.compose.service=app-$other")" ]; then
        pass "el color detenido (app-$other) sigue detenido"
    else
        fail "app-$other volvió a arrancar tras el reinicio"
    fi
fi

[ "$failures" = 0 ]
