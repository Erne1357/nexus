#!/bin/sh
# Respaldos de PostgreSQL de Nexus (ADR-0013). Corre dentro de la imagen de
# postgres ya fijada, en el servicio `backup` de compose.prod.yaml.
#
#   backup.sh daemon   bucle: un respaldo al día a BACKUP_TIME (hora de TZ)
#   backup.sh run      un respaldo ahora, más rotación (también para respaldar antes de actualizar)
#   backup.sh check    healthcheck: sale 0 si el último respaldo diario es reciente
#   backup.sh predeploy <etiqueta>
#                      respaldo previo a un despliegue con migraciones (ADR-0033): va a predeploy/, conserva
#                      los últimos BACKUP_KEEP_PREDEPLOY e imprime PREDEPLOY_FILE=<archivo> para el deployer
#
# La conexión usa las variables estándar PGHOST, PGUSER, PGPASSWORD y PGDATABASE.
# Formato custom (-Fc): se restaura con pg_restore, a una base nueva o a la misma.
set -eu
umask 077

BACKUP_DIR=${BACKUP_DIR:-/backups}
BACKUP_TIME=${BACKUP_TIME:-03:00}
KEEP_DAILY=${BACKUP_KEEP_DAILY:-14}
KEEP_WEEKLY=${BACKUP_KEEP_WEEKLY:-8}
KEEP_PREDEPLOY=${BACKUP_KEEP_PREDEPLOY:-5}
# Un respaldo diario más viejo que esto (minutos, 26 h) pone el servicio en unhealthy.
MAX_AGE_MIN=${BACKUP_MAX_AGE_MIN:-1560}

log() {
    printf '%s backup: %s\n' "$(date '+%Y-%m-%d %H:%M:%S %Z')" "$*"
}

# La conexión a PostgreSQL llega por variables PG*; sin ellas, fallar con un mensaje claro.
require_connection_env() {
    for var in PGHOST PGUSER PGPASSWORD PGDATABASE; do
        eval "value=\${$var:-}"
        if [ -z "$value" ]; then
            log "ERROR: falta la variable $var (revisa DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env)"
            exit 1
        fi
    done
}

# Conserva los $2 archivos más recientes de $1; los nombres llevan la fecha, así
# que el orden alfabético es el cronológico.
prune() {
    dir=$1
    keep=$2
    ls -1 "$dir"/*.dump 2>/dev/null | sort -r | tail -n +"$((keep + 1))" | while read -r old; do
        rm -f -- "$old"
        log "eliminado por retención: $old"
    done
}

# Escribe un pg_dump validado en $1/$2: primero como parcial y solo si pg_restore lo lee toma su nombre final.
dump_to() {
    dir=$1
    name=$2
    partial="$dir/.${name}.partial"
    mkdir -p "$dir"

    log "respaldando ${PGDATABASE}@${PGHOST} -> ${dir#"$BACKUP_DIR"/}/${name}"

    if ! pg_dump --format=custom --compress=6 --file="$partial"; then
        rm -f -- "$partial"
        log "ERROR: pg_dump falló"
        return 1
    fi

    # Un archivo que pg_restore no sabe leer no es un respaldo.
    if ! pg_restore --list "$partial" >/dev/null; then
        rm -f -- "$partial"
        log "ERROR: el archivo generado no es un respaldo válido"
        return 1
    fi

    mv -- "$partial" "$dir/$name"
    log "listo: ${dir#"$BACKUP_DIR"/}/${name} ($(du -h "$dir/$name" | cut -f1))"
}

run_backup() {
    mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"

    name="${PGDATABASE}-$(date +%Y%m%d-%H%M%S).dump"
    dump_to "$BACKUP_DIR/daily" "$name" || return 1

    # Los domingos queda además una copia semanal (una por semana ISO).
    if [ "$(date +%u)" = "7" ]; then
        weekly="${PGDATABASE}-$(date +%G-W%V).dump"
        cp -- "$BACKUP_DIR/daily/$name" "$BACKUP_DIR/weekly/$weekly"
        log "copia semanal: weekly/${weekly}"
    fi

    prune "$BACKUP_DIR/daily" "$KEEP_DAILY"
    prune "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"
}

# Respaldo previo a migrar: el nombre lleva la etiqueta (el SHA corto del release) y no entra en la rotación
# diaria, porque volver atrás de una contracción puede necesitarlo días después.
predeploy_backup() {
    label=$1
    case "$label" in
        "" | *[!A-Za-z0-9._-]*)
            log "ERROR: la etiqueta del respaldo previo solo admite letras, números, punto, guion y guion bajo"
            return 2
            ;;
    esac
    # Un reintento del mismo release reusa su respaldo: si no, cada intento fallido ocuparía un lugar de la retención.
    existing=$(ls -1 "$BACKUP_DIR/predeploy/"*"-${label}.dump" 2>/dev/null | sort | tail -n 1)
    if [ -n "$existing" ]; then
        log "ya hay un respaldo previo de ${label}: $(basename "$existing")"
        echo "PREDEPLOY_FILE=$(basename "$existing")"
        return 0
    fi
    name="${PGDATABASE}-$(date +%Y%m%d-%H%M%S)-${label}.dump"
    dump_to "$BACKUP_DIR/predeploy" "$name" || return 1
    prune "$BACKUP_DIR/predeploy" "$KEEP_PREDEPLOY"
    echo "PREDEPLOY_FILE=$name"
}

has_recent_backup() {
    [ -d "$BACKUP_DIR/daily" ] &&
        [ -n "$(find "$BACKUP_DIR/daily" -name '*.dump' -mmin "-${MAX_AGE_MIN}" 2>/dev/null)" ]
}

# Segundos que faltan para la próxima BACKUP_TIME (HH:MM) en la hora local.
seconds_until_next() {
    # ${x#0}: quita un cero inicial para que "08" no se lea como octal.
    now_h=$(date +%H)
    now_m=$(date +%M)
    now_s=$(date +%S)
    now=$((${now_h#0} * 3600 + ${now_m#0} * 60 + ${now_s#0}))

    at_h=${BACKUP_TIME%%:*}
    at_m=${BACKUP_TIME##*:}
    at=$((${at_h#0} * 3600 + ${at_m#0} * 60))

    wait=$((at - now))
    [ "$wait" -le 0 ] && wait=$((wait + 86400))
    echo "$wait"
}

daemon() {
    require_connection_env
    case "$BACKUP_TIME" in
        [0-2][0-9]:[0-5][0-9]) [ "${BACKUP_TIME%%:*}" -le 23 ] && valid=1 ;;
    esac
    if [ "${valid:-0}" != "1" ]; then
        log "ERROR: BACKUP_TIME debe ser HH:MM entre 00:00 y 23:59 (recibí '$BACKUP_TIME')"
        exit 1
    fi

    trap 'log "deteniendo"; exit 0' TERM INT
    log "iniciado: diario a las ${BACKUP_TIME} (${TZ:-UTC}); conserva ${KEEP_DAILY} diarios y ${KEEP_WEEKLY} semanales en ${BACKUP_DIR}"

    while true; do
        # Primer arranque, o servidor apagado a la hora del respaldo: no esperar al día siguiente.
        if ! has_recent_backup; then
            log "no hay respaldo reciente; respaldando ahora"
            run_backup || log "se reintenta a la hora programada"
        fi

        wait_s=$(seconds_until_next)
        log "próximo respaldo en ${wait_s}s"
        sleep "$wait_s" &
        wait $!

        run_backup || log "ERROR: respaldo fallido; el healthcheck lo marcará si pasa de ${MAX_AGE_MIN} min"
    done
}

case "${1:-daemon}" in
    daemon) daemon ;;
    run) require_connection_env && run_backup ;;
    check) has_recent_backup ;;
    predeploy) require_connection_env && predeploy_backup "${2:-}" ;;
    *)
        echo "uso: backup.sh [daemon|run|check|predeploy <etiqueta>]" >&2
        exit 2
        ;;
esac
