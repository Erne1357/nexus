# Despliegue y operación en producción

Runbook del servidor on-premise del ITCJ. La decisión y sus alternativas están en [ADR-0013](../decisiones/0013-despliegue-on-premise-itcj.md).

**Estado:** el stack se validó en una máquina de desarrollo el 2026-10-27 (construcción de la imagen, HTTPS con CA interna, migraciones al arrancar, actualización de versión, respaldo y restauración). **Falta ensayarlo en el servidor real** y confirmar con el ITCJ los puntos de la sección [Por confirmar](#por-confirmar-con-el-itcj). Mientras tanto, los comandos de abajo son los que se probaron; las rutas del servidor (`/opt/nexus`, `/var/backups/nexus`) son una sugerencia.

## Qué corre

`docker compose` con dos archivos: `compose.yaml` (base) y `compose.prod.yaml` (producción). La plantilla del `.env` define `COMPOSE_FILE=compose.yaml:compose.prod.yaml`, así que en el servidor basta escribir `docker compose ...` sin `-f`.

| Servicio | Qué hace | Se ve desde fuera |
|---|---|---|
| `caddy` | HTTPS con Let's Encrypt (obtiene y renueva solo), redirige 80 a 443 y reenvía a `app` | Sí: puertos 80, 443 TCP y 443 UDP |
| `app` | Laravel + Octane; ejecuta las migraciones al arrancar | No |
| `queue` | Worker de colas (misma imagen que `app`) | No |
| `postgres` | Base de datos (volumen `postgres-data`) | No |
| `valkey` | Caché, sesiones y colas (volumen `valkey-data`) | No |
| `backup` | `pg_dump` diario hacia `BACKUP_DIR` con retención | No |

Volúmenes de Docker: `postgres-data`, `valkey-data`, `app-storage` (archivos compartidos entre `app` y `queue`), `caddy-data` (certificados) y `caddy-config`. **Nunca ejecutes `docker compose down -v` en producción**: borra la base de datos y los certificados.

## Requisitos del servidor

- Linux con Docker Engine y el plugin de Compose v2 (probado con Docker 29 y Compose 5.0), `git` y `openssl`. El usuario que despliega pertenece al grupo `docker`.
- Un nombre público (`APP_DOMAIN`) con registro DNS hacia la IP del servidor.
- Puertos **80 y 443** abiertos desde internet hacia el servidor. Sin ellos Let's Encrypt no emite el certificado.
- Salida a internet hacia Docker Hub, GitHub, Packagist, npm y `acme-v02.api.letsencrypt.org`.
- Reloj sincronizado (NTP): con el reloj desfasado falla la emisión del certificado.
- Docker arranca con el sistema (`sudo systemctl enable docker`). Los contenedores tienen `restart: unless-stopped`, así que vuelven solos tras un reinicio.
- Espacio: la base, 22 respaldos (14 diarios y 8 semanales), las imágenes (cada versión pesa cerca de 1 GB) y los registros (hasta 50 MB por servicio).

## Primer despliegue

1. **Obtener el código** en una versión etiquetada (para el servidor de pruebas, la rama `develop`):

   ```bash
   sudo install -d -o "$USER" /opt/nexus
   git clone https://github.com/Erne1357/nexus.git /opt/nexus
   cd /opt/nexus
   git checkout v1.0.0        # o: git checkout develop
   ```

2. **Crear el `.env`** a partir de la plantilla, con permisos restringidos:

   ```bash
   cp .env.production.example .env
   chmod 600 .env
   ```

   Edita `.env` y reemplaza todo lo que diga `CAMBIAR` o `example`:

   | Variable | Valor |
   |---|---|
   | `APP_DOMAIN` | El nombre público, sin `https://` |
   | `ACME_EMAIL` | Correo de TI que recibe los avisos de vencimiento |
   | `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` |
   | `DB_PASSWORD`, `REDIS_PASSWORD` | `openssl rand -hex 24` (sin `$` ni espacios) |
   | `APP_IMAGE_TAG` | El tag que desplegarás (`v1.0.0`) |
   | `BACKUP_DIR`, `BACKUP_UID`, `BACKUP_GID` | Ver el paso siguiente |

   Guarda una copia del `.env` **fuera del servidor**, en un lugar con acceso restringido: sin `APP_KEY` no se pueden descifrar las sesiones ni los valores cifrados, y las contraseñas no se pueden recuperar.

3. **Crear el directorio de respaldos.** Contendrá datos personales de alumnos y docentes:

   ```bash
   sudo install -d -o "$(id -u)" -g "$(id -g)" -m 700 /var/backups/nexus
   ```

   y en `.env`: `BACKUP_DIR=/var/backups/nexus`, `BACKUP_UID=$(id -u)`, `BACKUP_GID=$(id -g)` (con `0` los archivos quedan como `root`).

4. **Construir y levantar.** El primer arranque ejecuta las migraciones y tarda un par de minutos:

   ```bash
   docker compose config -q                       # valida el compose y que no falte ninguna variable
   docker compose build app
   docker compose up -d --wait --wait-timeout 300
   docker compose ps
   ```

   Si usas el staging de Let's Encrypt para ensayar (recomendado la primera vez, para no gastar el límite de certificados), agrega a `.env` antes del `up`:
   `CADDY_GLOBAL_OPTIONS=acme_ca https://acme-staging-v02.api.letsencrypt.org/directory`. El navegador mostrará el certificado como no confiable; es lo esperado. Quita la línea y ejecuta `docker compose up -d` para pasar al certificado real.

5. **Verificar:**

   ```bash
   curl -fsS https://nexus.example.edu.mx/up                 # 200 si la app está sana
   curl -sI http://nexus.example.edu.mx/up | head -1         # 308: redirige a HTTPS
   docker compose logs backup | tail -5                      # primer respaldo hecho al arrancar
   ls -l /var/backups/nexus/daily
   ```

6. **Primer usuario administrador:** pendiente. Lo definen las tareas de usuarios y roles del módulo `Core` (0.5 a 0.7) y la importación de la fase 1; cuando exista el comando o seeder, se documenta aquí. Por ahora la verificación termina en `/up` y en que la página de inicio cargue.

## Actualizar a una versión nueva

Cada versión es un tag de git. La aplicación queda sin responder solo mientras `app` se recrea y migra; en la prueba local, las peticiones que llegaron en ese lapso esperaron y respondieron 200.

```bash
cd /opt/nexus
git fetch --tags
git diff --stat v1.0.0 v1.0.1 -- compose.yaml compose.prod.yaml docker .env.production.example   # ¿cambió la infraestructura?

# 1. Respaldo previo, por si una migración sale mal
docker compose exec backup sh /opt/backup/backup.sh run

# 2. Cambiar de versión
git checkout v1.0.1
sed -i 's/^APP_IMAGE_TAG=.*/APP_IMAGE_TAG=v1.0.1/' .env

# 3. Construir (el sitio sigue sirviendo la versión anterior) y recrear
docker compose build app
docker compose up -d --wait --wait-timeout 300

# 4. Verificar
docker compose ps
curl -fsS https://nexus.example.edu.mx/up
docker compose logs --tail 40 app          # migraciones aplicadas
```

- Si el `diff` muestra cambios en `.env.production.example`, agrega las variables nuevas a tu `.env` **antes** del paso 3.
- Si cambió `docker/caddy/Caddyfile`, aplícalo sin interrumpir: `docker compose exec caddy caddy reload --config /etc/caddy/Caddyfile`. Si cambió `docker/backup/backup.sh`, `docker compose restart backup`.
- Cuando un cambio de `.env` o de los compose lo requiere, `docker compose up -d` recrea solo los servicios afectados.

### Volver a la versión anterior

```bash
sed -i 's/^APP_IMAGE_TAG=.*/APP_IMAGE_TAG=v1.0.0/' .env
git checkout v1.0.0
docker compose up -d --wait        # la imagen anterior sigue en el servidor si no la borraste
```

Las migraciones **no se revierten solas**. Si la versión nueva ya migró y el código anterior no funciona con ese esquema, restaura el respaldo del paso 1 con el procedimiento B de abajo.

Limpieza: `docker image ls nexus-app` lista las versiones; borra las viejas con `docker rmi nexus-app:<tag>` y conserva al menos la anterior para poder volver atrás. `docker builder prune` libera la caché de construcción.

## Respaldos

El servicio `backup` hace un `pg_dump` en formato custom (`-Fc`, comprimido) a las 03:00 hora de Ciudad Juárez y lo valida antes de dejarlo. Estructura de `BACKUP_DIR`:

```
/var/backups/nexus/
├── daily/    nexus-20261027-030000.dump   (últimos 14)
└── weekly/   nexus-2026-W44.dump          (últimas 8; la copia del domingo)
```

- Si al arrancar no hay un respaldo de menos de 26 horas (primer despliegue, servidor apagado a las 03:00), respalda de inmediato.
- El contenedor pasa a `unhealthy` si el último respaldo diario tiene más de 26 horas: revísalo con `docker compose ps`.
- Respaldo manual: `docker compose exec backup sh /opt/backup/backup.sh run`.
- Ajustes en `.env`: `BACKUP_TIME`, `BACKUP_KEEP_DAILY`, `BACKUP_KEEP_WEEKLY`.
- Contenido: solo la base de datos, que es la fuente de verdad. Los archivos de `app-storage` son importaciones y exportes en proceso, y se pueden volver a generar.
- **Copia fuera del servidor:** a cargo del ITCJ. Mientras no exista, perder el disco es perder los datos. Quien copie `BACKUP_DIR` debe tratarlo como información confidencial (datos personales), por ejemplo con `rsync` desde otro equipo o a un almacenamiento cifrado.

## Restaurar un respaldo

Un respaldo que nunca se restauró no es un respaldo. Haz el procedimiento A por lo menos una vez antes del piloto y después cada mes.

Elige el archivo:

```bash
ls -1 /var/backups/nexus/daily | tail -5
ARCHIVO=nexus-20261027-030000.dump
```

### A. Verificar en una base nueva (sin afectar producción)

```bash
docker compose exec backup sh -c "createdb nexus_restore && pg_restore --no-owner --exit-on-error --single-transaction -d nexus_restore /backups/daily/$ARCHIVO"
docker compose exec backup psql -d nexus_restore -c 'select count(*) from users'
docker compose exec backup psql -d nexus_restore -c 'select count(*) from migrations'
docker compose exec backup dropdb nexus_restore
```

Compara los conteos con la base real (`-d nexus`). `--single-transaction` y `--exit-on-error` hacen que, si algo falla, la base nueva quede vacía en lugar de a medias.

### B. Recuperar producción desde un respaldo

Reemplaza la base real: todo lo escrito después del respaldo se pierde.

```bash
docker compose stop app queue
docker compose exec backup sh -c "dropdb --force \"\$PGDATABASE\" && createdb \"\$PGDATABASE\" && pg_restore --no-owner --exit-on-error --single-transaction -d \"\$PGDATABASE\" /backups/daily/$ARCHIVO"
docker compose up -d --wait
curl -fsS https://nexus.example.edu.mx/up
```

Si restauras un respaldo anterior a una migración, vuelve también al tag que coincide con ese esquema (ver "Volver a la versión anterior"); de lo contrario `app` aplicará las migraciones pendientes al arrancar.

### Servidor perdido

En un servidor nuevo: pasos 1 a 4 del primer despliegue (con el `.env` guardado fuera del servidor), copia el archivo `.dump` más reciente a `BACKUP_DIR/daily/` y aplica el procedimiento B.

## Certificados HTTPS

- Caddy obtiene el certificado la primera vez que arranca y lo renueva solo, unos 30 días antes de que venza. No hay nada que programar.
- Los certificados viven en el volumen `caddy-data`. No lo borres: Let's Encrypt limita los certificados repetidos para el mismo nombre.
- Para ver el estado: `docker compose logs caddy | grep -E 'tls|error'`.
- Mantén abiertos los puertos 80 y 443 y el DNS apuntando al servidor, o la renovación fallará. Let's Encrypt avisa por correo a `ACME_EMAIL` cuando un certificado está por vencer sin renovarse.
- Si el ITCJ no puede abrir los puertos o prefiere su propio certificado, el plan B (certificado entregado por el ITCJ o desafío DNS) está descrito en el ADR-0013.

## Operación cotidiana

```bash
docker compose ps                          # estado y salud
docker compose logs -f --tail 100 app      # también: queue, caddy, backup, postgres
docker compose restart app                 # reinicia un servicio
docker compose exec app php artisan queue:failed         # jobs fallidos
docker compose exec app php artisan queue:retry all
docker system df                           # espacio usado por Docker
```

Cambiar una variable del `.env` y ejecutar `docker compose up -d` recrea los servicios afectados. Dos casos especiales:

- **`DB_PASSWORD`:** PostgreSQL solo lee la contraseña inicial al crear el volumen. Primero cámbiala en la base y luego en `.env`; `up -d` recrea `postgres`, `app`, `queue` y `backup` con la nueva (la base conserva sus datos):

  ```bash
  docker compose exec postgres psql -U nexus -c "ALTER USER nexus PASSWORD 'NUEVA_CONTRASEÑA'"
  sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=NUEVA_CONTRASEÑA/' .env
  docker compose up -d --wait
  ```

- **`REDIS_PASSWORD`:** basta editarla en `.env` y ejecutar `docker compose up -d`; recrea `valkey`, `app` y `queue`, y las sesiones y la cola se conservan (están en el volumen `valkey-data`).

## Si algo falla

| Síntoma | Qué revisar |
|---|---|
| El sitio no abre | `docker compose ps`: ¿están `healthy` `caddy` y `app`? Si falta alguno, `docker compose logs --tail 100 <servicio>` |
| Error de certificado en el navegador | `docker compose logs caddy \| grep -E 'tls.obtain\|error'`. "could not get certificate from issuer" trae el motivo: tiempo de espera (el 80 o el 443 no llegan al servidor), nombre que no resuelve (DNS), o límite de certificados excedido (esperar o usar staging). Con `CADDY_GLOBAL_OPTIONS` con el staging puesto, el certificado no es confiable a propósito |
| 502 o respuesta lenta justo tras actualizar | `app` está migrando o arrancando (hasta `APP_START_PERIOD`, 120 s). Mira `docker compose logs -f app` |
| `app` se reinicia en bucle | Casi siempre una migración o una variable faltante: `docker compose logs --tail 100 app` ("No application encryption key" = falta `APP_KEY`; error SQL = migración fallida). Corrige y `docker compose up -d`, o restaura el respaldo previo |
| `required variable ... is missing a value` al ejecutar compose | Falta `APP_DOMAIN`, `ACME_EMAIL` u otra en `.env` |
| `backup` aparece `unhealthy` | `docker compose logs backup`. Causas comunes: `BACKUP_DIR` sin permiso de escritura para `BACKUP_UID`, disco lleno o `postgres` caído. Prueba con `docker compose exec backup sh /opt/backup/backup.sh run` |
| Las tareas en cola no avanzan | `docker compose logs queue`; `docker compose exec app php artisan queue:failed` |
| Disco lleno | `docker system df`; `docker rmi` de versiones viejas; revisa el tamaño de `BACKUP_DIR` |
| Sesiones cerradas de repente | Cambió `APP_KEY` o se perdió el volumen `valkey-data` |

## Probar el stack de producción en una máquina de desarrollo

Sirve para validar cambios al overlay sin servidor. **No toca el stack de desarrollo** si se usa otro nombre de proyecto (`-p`) y otros puertos. Hazlo desde un worktree o una copia del repositorio con su propio `.env`, porque `env_file: .env` apunta a esa carpeta.

```bash
cp .env.production.example .env          # y completa: APP_KEY, contraseñas, APP_DOMAIN=nexus-prodcheck.test
#   APP_IMAGE_TAG=prodcheck  HTTP_PORT=18080  HTTPS_PORT=18443
#   CADDY_GLOBAL_OPTIONS=local_certs     (CA interna: no sale a internet)
#   BACKUP_DIR=/ruta/de/prueba

P="docker compose -p nexus-prodcheck"    # siempre con -p: sin él, compose usaría el proyecto "nexus" de desarrollo
$P build app
$P up -d --wait --wait-timeout 300
curl -sk --resolve nexus-prodcheck.test:18443:127.0.0.1 https://nexus-prodcheck.test:18443/up
$P exec backup sh /opt/backup/backup.sh run
$P down -v                               # borra solo este proyecto de prueba
```

La CA interna de Caddy no es de confianza en el navegador, de ahí el `-k`. Las pruebas automáticas de la configuración están en `tests/Unit/Deploy/ProductionStackTest.php`.

## Por confirmar con el ITCJ

Pendientes que no se pueden resolver desde el repositorio (detalle y motivo en el [ADR-0013](../decisiones/0013-despliegue-on-premise-itcj.md#por-confirmar-con-el-itcj)):

1. Nombre del dominio.
2. Registro DNS hacia el servidor y quién lo administra.
3. Puertos 80 y 443 abiertos desde internet hacia el servidor.
4. Características del servidor (CPU, RAM, disco, sistema operativo, IP fija, NTP) y si Docker está instalado.
5. Quién tiene acceso (SSH, `sudo`, grupo `docker`) y desde qué red.
6. Salida a internet y, si hay, proxy institucional.
7. Responsable y frecuencia de la copia de `BACKUP_DIR` fuera del servidor.
8. Servidor de pruebas: máquina y dominio donde se despliega `develop` (criterio de cierre de la tarea 0.14).
9. Correo saliente, si algún flujo lo requiere.
