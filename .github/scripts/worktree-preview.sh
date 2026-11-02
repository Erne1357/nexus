#!/usr/bin/env bash
# Sirve un worktree en el navegador sin tocar la app de la carpeta principal (http://localhost) ni su base.
#
# Levanta un contenedor aparte con la misma imagen, volumen y red que `app` (docker compose run), que corre
# `php artisan serve` desde .worktrees/<dir> con su propia base de datos y lo publica en http://localhost:<puerto>.
# Postgres y Valkey se comparten; para no pisarse con la app principal ni con otros previews, cada uno lleva
# su cookie de sesión, sus prefijos de Valkey (sesiones y caché) y la cola en `sync` (el worker `queue` sirve la
# base `nexus`). Los assets van compilados en el worktree (`build`), que no tiene `public/hot`.
#
# Se corre desde la carpeta principal del repo. Detalle en CONTRIBUTING.md, "Worktrees con Docker".
#
# Uso:
#   worktree-preview.sh up    <dir> <puerto> <base>   levanta el servidor (crea la base si falta)
#   worktree-preview.sh seed  <dir> <puerto> <base>   migrate:fresh + DemoSeeder; abre 20271 en C2; admin con `password`
#   worktree-preview.sh build <dir>                   npm install + vite build del worktree (una vez)
#   worktree-preview.sh watch <dir>                   vite build --watch del worktree en segundo plano
#   worktree-preview.sh down  <dir> <puerto> [--drop <base>]   detiene el servidor (y borra la base si se pide)
#
# Ejemplo: worktree-preview.sh up feature-38-career-proposal 8092 nexus_review_c
set -euo pipefail

usage() {
    sed -n '12,19p' "$0" | sed 's/^# \{0,1\}//' >&2
    exit 1
}

[[ $# -ge 2 ]] || usage

action="$1"
dir="$2"
workdir="/app/.worktrees/${dir}"

[[ -d ".worktrees/${dir}" ]] || { echo "No existe .worktrees/${dir}; corre el script desde la carpeta principal del repo." >&2; exit 1; }

guard_database() {
    case "$1" in
        nexus | nexus_test | postgres | template0 | template1 | "")
            echo "La base «$1» no es de preview: usa una propia, por ejemplo nexus_review_<x>." >&2
            exit 1
            ;;
    esac
}

container() {
    echo "nexus-preview-$1"
}

case "$action" in
    up)
        [[ $# -eq 4 ]] || usage
        port="$3"
        database="$4"
        guard_database "$database"

        if ! docker compose exec -T postgres psql -U nexus -d postgres -tAc "select 1 from pg_database where datname = '${database}'" | grep -q 1; then
            docker compose exec -T postgres createdb -U nexus "$database"
        fi

        docker rm -f "$(container "$port")" >/dev/null 2>&1 || true

        docker compose run -d --no-deps --name "$(container "$port")" -p "${port}:8000" -w "$workdir" \
            -e DB_DATABASE="$database" \
            -e APP_URL="http://localhost:${port}" \
            -e SESSION_COOKIE="nexus_preview_${port}_session" \
            -e REDIS_PREFIX="nexus-preview-${port}-database-" \
            -e CACHE_PREFIX="nexus-preview-${port}-cache-" \
            -e QUEUE_CONNECTION=sync \
            -e PHP_CLI_SERVER_WORKERS=4 \
            app php artisan serve --host=0.0.0.0 --port=8000 >/dev/null

        echo "Preview de ${dir} con la base ${database}: http://localhost:${port}"
        ;;

    seed)
        [[ $# -eq 4 ]] || usage
        port="$3"
        database="$4"
        guard_database "$database"

        docker exec "$(container "$port")" php artisan migrate:fresh --force --no-interaction
        docker exec "$(container "$port")" php artisan db:seed --force --no-interaction --class='Database\Seeders\DemoSeeder'
        docker exec "$(container "$port")" php artisan tinker --execute '
            $admin = App\Modules\Core\Models\User::query()->where("username", "admin")->firstOrFail();
            $admin->forceFill(["password" => Illuminate\Support\Facades\Hash::make("password"), "must_change_password" => false])->save();
            auth()->login($admin);
            app(App\Modules\Core\Actions\OpenTerm::class)->handle(
                App\Modules\Core\Models\Term::query()->where("code", "20271")->firstOrFail(),
                App\Modules\Core\Models\Campus::query()->where("code", "C2")->firstOrFail(),
            );
            echo "20271 abierto en C2; admin y el personal demo entran con password\n";
        '
        ;;

    build)
        docker compose exec -T -w "$workdir" vite npm install --no-audit --no-fund
        docker compose exec -T -w "$workdir" vite npx vite build
        ;;

    watch)
        docker compose exec -d -w "$workdir" vite npx vite build --watch
        echo "vite build --watch corriendo para ${dir}; se detiene con: worktree-preview.sh down ${dir} <puerto>"
        ;;

    down)
        [[ $# -ge 3 ]] || usage
        port="$3"
        docker rm -f "$(container "$port")" >/dev/null 2>&1 || true

        # El watch de vite corre en el contenedor compartido `vite`: se detiene el de este worktree.
        docker compose exec -T vite sh -c "for p in /proc/[0-9]*; do
            if tr '\0' ' ' < \$p/cmdline 2>/dev/null | grep -q 'vite build --watch' && [ \"\$(readlink \$p/cwd)\" = '${workdir}' ]; then
                kill \${p#/proc/}
            fi
        done" || true

        if [[ "${4:-}" == "--drop" ]]; then
            database="${5:-}"
            guard_database "$database"
            docker compose exec -T postgres dropdb -U nexus --if-exists "$database"
        fi

        echo "Preview de ${dir} detenido."
        ;;

    *)
        usage
        ;;
esac
