#!/bin/sh
# Respaldos de PostgreSQL de Nexus (ADR-0013). Corre dentro de la imagen de
# postgres ya fijada, en el servicio `backup` de compose.prod.yaml.
#
#   backup.sh daemon   bucle: un respaldo al día a BACKUP_TIME (hora de TZ)
#   backup.sh run      un respaldo ahora, más rotación (también para respaldar antes de actualizar)
#   backup.sh check    healthcheck: sale 0 si el último respaldo diario es reciente
#
# La conexión usa las variables estándar PGHOST, PGUSER, PGPASSWORD y PGDATABASE.
# Formato custom (-Fc): se restaura con pg_restore, a una base nueva o a la misma.
set -eu
umask 077

BACKUP_DIR=${BACKUP_DIR:-/backups}
BACKUP_TIME=${BACKUP_TIME:-03:00}
KEEP_DAILY=${BACKUP_KEEP_DAILY:-14}
KEEP_WEEKLY=${BACKUP_KEEP_WEEKLY:-8}
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

run_backup() {
    mkdir -p "$BACKUP_DIR/daily" "$BACKUP_DIR/weekly"

    name="${PGDATABASE}-$(date +%Y%m%d-%H%M%S).dump"
    partial="$BACKUP_DIR/daily/.${name}.partial"

    log "respaldando ${PGDATABASE}@${PGHOST} -> daily/${name}"

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

    mv -- "$partial" "$BACKUP_DIR/daily/$name"
    log "listo: daily/${name} ($(du -h "$BACKUP_DIR/daily/$name" | cut -f1))"

    # Los domingos queda además una copia semanal (una por semana ISO).
    if [ "$(date +%u)" = "7" ]; then
        weekly="${PGDATABASE}-$(date +%G-W%V).dump"
        cp -- "$BACKUP_DIR/daily/$name" "$BACKUP_DIR/weekly/$weekly"
        log "copia semanal: weekly/${weekly}"
    fi

    prune "$BACKUP_DIR/daily" "$KEEP_DAILY"
    prune "$BACKUP_DIR/weekly" "$KEEP_WEEKLY"
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
    *)
        echo "uso: backup.sh [daemon|run|check]" >&2
        exit 2
        ;;
esac
