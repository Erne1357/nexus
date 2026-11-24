# shellcheck shell=bash
# Conseguir un release y dejarlo listo en $RELEASES_DIR/<sha> (ADR-0033).
#
# Un paquete (nexus-bundle) es un .tar con:
#   manifest.json   {"sha", "created_at", "images": {"app"|"solver"|"deployer": {"ref", "file"}},
#                    "third_party": [{"ref", "local", "file"}]}
#   images/*        cada imagen de `docker save` (zstd, salvo la del deployer, que va sin comprimir para poder
#                   cargarla en el primer arranque con solo `tar` y `docker`)
#   infra.tar       compose.yaml, compose.prod.yaml, docker/ y .env.production.example de ese SHA
# y junto a él, <paquete>.sha256 (salida de sha256sum).

# Verifica, carga y materializa un paquete. Imprime el SHA del release.
release_from_bundle() {
    local bundle=$1 dir name work sha
    [ -f "$bundle" ] || die "$E_BUNDLE" "no existe el paquete $bundle"
    dir=$(cd "$(dirname "$bundle")" && pwd)
    name=$(basename "$bundle")
    [ -f "$dir/$name.sha256" ] || die "$E_BUNDLE" "falta $name.sha256 junto al paquete (se descarga con él)"

    log "verificando $name"
    # Se compara la suma del paquete mismo con la del .sha256 (no el archivo que el .sha256 nombre).
    local expected actual
    expected=$(awk 'NR == 1 { print $1 }' "$dir/$name.sha256")
    actual=$(sha256sum <"$bundle" | awk '{ print $1 }')
    [[ "$expected" =~ ^[0-9a-f]{64}$ ]] && [ "$expected" = "$actual" ] ||
        die "$E_BUNDLE" "el paquete $name no coincide con su sha256: está incompleto o alterado; no se despliega"

    work=$(mktemp -d "$RELEASES_DIR/.incoming.XXXXXX")
    tar -xf "$bundle" -C "$work" || {
        rm -rf -- "$work"
        die "$E_BUNDLE" "no se pudo abrir $name"
    }
    sha=$(jq -er '.sha' "$work/manifest.json") && valid_sha "$sha" &&
        jq -e '[(.images[].file), (.third_party[].file)] | all(test("^images/[A-Za-z0-9._-]+$"))' "$work/manifest.json" >/dev/null || {
        rm -rf -- "$work"
        die "$E_BUNDLE" "$name no trae un manifest.json válido"
    }

    log "cargando las imágenes de $(short "$sha")"
    local file ref
    while IFS=$'\t' read -r file ref; do
        load_image "$work/$file" || {
            rm -rf -- "$work"
            die "$E_BUNDLE" "no se pudo cargar $file"
        }
        docker image inspect "$ref" >/dev/null 2>&1 || {
            rm -rf -- "$work"
            die "$E_BUNDLE" "después de cargar $file no aparece la imagen $ref"
        }
    done < <(jq -r '(.images[] | [.file, .ref]), (.third_party[] | [.file, .local]) | @tsv' "$work/manifest.json")

    materialize_release "$sha" "$work/infra.tar" "$work/manifest.json" bundle
    rm -rf -- "$work"
    printf '%s\n' "$sha"
}

# Con internet (DEPLOY_SOURCE=github): el release sale del payload de un Deployment de GitHub
# ({"sha", "images": {"app", "solver", "deployer"}}, cada una por digest) y la infraestructura del tarball de ese
# SHA en codeload (repo público; respeta HTTPS_PROXY). Imprime el SHA.
release_from_github() {
    local payload=$1 sha work ref
    local prefix=${DEPLOY_IMAGE_PREFIX:-ghcr.io/erne1357/nexus-} pattern
    sha=$(jq -er '.sha' "$payload") && valid_sha "$sha" || die "$E_BUNDLE" "el Deployment no trae un sha de release válido"
    # Solo imágenes propias y por digest: el deployer administra el servidor y se actualiza a la imagen que diga aquí.
    pattern="^${prefix//./\\.}(app|solver|deployer)@sha256:[0-9a-f]{64}\$"
    if [ "${DEPLOY_ALLOW_TAG_REFS:-false}" = true ]; then
        pattern="^${prefix//./\\.}(app|solver|deployer)([@:][A-Za-z0-9._:-]+)\$"
    fi
    jq -e --arg p "$pattern" '[.images.app, .images.solver, .images.deployer] | all(type == "string" and test($p))' \
        "$payload" >/dev/null || die "$E_BUNDLE" "el Deployment pide imágenes que no son de $prefix por digest; no se despliega"
    work=$(mktemp -d "$RELEASES_DIR/.incoming.XXXXXX")
    for ref in $(jq -r '.images[]' "$payload"); do
        docker image inspect "$ref" >/dev/null 2>&1 || docker pull -q "$ref" >/dev/null ||
            { rm -rf -- "$work"; die "$E_BUNDLE" "no se pudo descargar $ref"; }
    done
    mkdir -p "$work/tree"
    curl -fsSL "${DEPLOY_GITHUB_CODELOAD:-https://codeload.github.com}/${DEPLOY_REPOSITORY:-Erne1357/nexus}/tar.gz/$sha" |
        tar -xz -C "$work/tree" --strip-components=1 --wildcards \
            '*/compose.yaml' '*/compose.prod.yaml' '*/.env.production.example' '*/docker/*' ||
        { rm -rf -- "$work"; die "$E_BUNDLE" "no se pudo descargar la infraestructura de $(short "$sha")"; }
    tar -cf "$work/infra.tar" -C "$work/tree" compose.yaml compose.prod.yaml docker .env.production.example
    # Las de terceros, con la misma etiqueta local que en un paquete: cambiar de fuente no recrea PostgreSQL ni Valkey.
    local third='[]' local_ref
    while read -r ref; do
        local_ref=$(local_tag "$ref")
        docker image inspect "$ref" >/dev/null 2>&1 || docker pull -q "$ref" >/dev/null ||
            { rm -rf -- "$work"; die "$E_BUNDLE" "no se pudo descargar $ref"; }
        docker tag "$ref" "$local_ref"
        third=$(jq -c --arg ref "$ref" --arg local "$local_ref" '. + [{ref: $ref, local: $local, file: "-"}]' <<<"$third")
    done < <(third_party_refs "$work/tree")
    jq --arg created "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" --argjson third "$third" \
        '{sha, created_at: $created, images: (.images | map_values({ref: .})), third_party: $third}' \
        "$payload" >"$work/manifest.json"
    materialize_release "$sha" "$work/infra.tar" "$work/manifest.json" github
    rm -rf -- "$work"
    printf '%s\n' "$sha"
}

# El sha de un release se vuelve nombre de carpeta: solo letras minúsculas y números.
valid_sha() {
    [[ "$1" =~ ^[0-9a-z]{7,40}$ ]]
}

# Imágenes de terceros que fijan los compose de un árbol (tag + digest), como las empaqueta nexus-bundle.
third_party_refs() {
    sed -n 's/^[[:space:]]*image:[[:space:]]*\([^[:space:]]*@sha256:[0-9a-f]\{64\}\).*/\1/p' "$1/compose.yaml" "$1/compose.prod.yaml" | sort -u
}

# La etiqueta local con que viaja una imagen de terceros (nexus-bundle usa la misma regla).
local_tag() {
    local ref=$1 name digest
    name=${ref%%[:@]*}
    name=${name##*/}
    digest=${ref##*@sha256:}
    printf 'nexus-local/%s:%s\n' "$name" "${digest:0:12}"
}

load_image() {
    case "$1" in
        *.zst) zstd -dcq "$1" | docker load -q >/dev/null ;;
        *) docker load -q -i "$1" >/dev/null ;;
    esac
}

# materialize_release <sha> <infra.tar> <manifest.json> <bundle|github>
materialize_release() {
    local sha=$1 infra=$2 manifest=$3 source=$4 dir="$RELEASES_DIR/$1"
    rm -rf -- "$dir"
    mkdir -p "$dir"
    tar -xf "$infra" -C "$dir"
    cp "$manifest" "$dir/manifest.json"
    # El .env vive fuera de los releases; Compose lo busca en la carpeta del proyecto.
    ln -s "$NEXUS_HOME/.env" "$dir/.env"
    printf '%s\n' "$source" >"$dir/source"
}

# Revisa el .env contra la plantilla del release, genera compose.release.yaml y valida el stack completo.
prepare_release() {
    local sha=$1
    [ -d "$RELEASES_DIR/$sha" ] || die "$E_USAGE" "el release $(short "$sha") no está en el servidor ($RELEASES_DIR)"
    check_env_keys "$sha"
    write_release_compose "$sha" "$(cat "$RELEASES_DIR/$sha/source")"
    compose "$sha" config -q || die "$E_ENV" "docker compose config falló con el .env del servidor"
}

# compose.release.yaml (JSON, que también es YAML): la imagen exacta de cada servicio. Con un paquete, nada se
# descarga (`pull_policy: never`) y las imágenes de terceros usan la etiqueta local con que viajaron.
write_release_compose() {
    local sha=$1 source=$2 dir="$RELEASES_DIR/$1" services
    # --profile '*': también los servicios con perfil (migrate, que corre con `run`).
    services=$(compose_base "$sha" --profile '*' config --format json | jq -c '.services | map_values(.image)') ||
        die "$E_ENV" "docker compose config falló con el .env del servidor (¿falta una variable obligatoria?)"
    jq -n --argjson services "$services" --slurpfile manifest "$dir/manifest.json" --arg source "$source" '
        ($manifest[0]) as $m
        | ($m.third_party | map({key: .ref, value: .local}) | from_entries) as $local
        | {services: ($services | to_entries | map(
              .key as $name
              | (if ($name | test("^(app|app-blue|app-green|migrate|queue|scheduler)$")) then $m.images.app.ref
                 elif $name == "solver" then $m.images.solver.ref
                 elif $name == "deployer" then $m.images.deployer.ref
                 else ($local[.value] // .value) end) as $image
              | {key: $name, value: ({image: $image} + (if $source == "bundle" then {pull_policy: "never"} else {} end))}
          ) | from_entries)}' >"$dir/compose.release.yaml"
}

# El .env del servidor debe traer toda llave que la plantilla del release define (sin contar las comentadas), y
# Compose debe poder interpolar los archivos. Si no, nada se toca.
check_env_keys() {
    local sha=$1 dir="$RELEASES_DIR/$1" missing
    [ -f "$NEXUS_HOME/.env" ] || die "$E_ENV" "falta $NEXUS_HOME/.env (se crea desde .env.production.example)"
    missing=$(comm -23 \
        <(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$dir/.env.production.example" | sort -u) \
        <(sed -n 's/^\([A-Z][A-Z0-9_]*\)=.*/\1/p' "$NEXUS_HOME/.env" | sort -u))
    if [ -n "$missing" ]; then
        die "$E_ENV" "el release $(short "$sha") pide variables que el .env del servidor no tiene: $(echo "$missing" | tr '\n' ' ')(cópialas de releases/$sha/.env.production.example y vuelve a desplegar)"
    fi
}
