# Despliegue y operación en producción

Runbook del servidor on-premise del ITCJ. Las decisiones y sus alternativas están en el [ADR-0013](../decisiones/0013-despliegue-on-premise-itcj.md) (el servidor, HTTPS y respaldos) y el [ADR-0033](../decisiones/0033-despliegue-continuo.md) (despliegue continuo sin caídas).

**Estado:** el stack de producción se ensayó desde cero el 2026-11-10 ([Ensayo local](#ensayo-local-2026-11-10)), y desde el 23 de noviembre de 2026 el despliegue continuo se prueba solo en cada PR hacia `main` (job `prod-smoke`: primer arranque, despliegue y rollback con tráfico continuo, versión rota, paquete alterado, contracción y reinicio de Docker). **Falta ejecutarlo en el servidor real** y confirmar con el ITCJ los puntos de [Por confirmar](#por-confirmar-con-el-itcj). El servidor ya existe, pero hoy **no tiene salida a internet**: cada versión llega en un paquete que se copia a mano (decisión del usuario del 23 de noviembre de 2026).

## Cómo llega una versión

1. Un PR de `develop` a `main` pasa `lint`, `tests`, `browser` y `prod-smoke`, y se mezcla.
2. `deploy.yml` construye una vez las imágenes (app, motor y deployer), las publica en GHCR con el tag `sha-<sha>`, corre el smoke con esas mismas imágenes y deja el **paquete del release** como artefacto del workflow: `nexus-<sha>.tar` y `nexus-<sha>.tar.sha256` (30 días).
3. **Sin internet (hoy):** alguien descarga el artefacto en una máquina con acceso, lo copia a `/opt/nexus/inbox/` del servidor, y el deployer lo despliega solo en menos de un minuto ([Actualizar](#actualizar-a-una-versión-nueva)).
   **Con internet:** el deployer toma el despliegue de GitHub, descarga por digest y publica el resultado; el workflow falla si el despliegue falló, y GitHub avisa por correo.
4. El despliegue es **blue/green**: la versión nueva arranca junto a la vieja, se espera a que su `/health` esté sano y Caddy cambia todo el tráfico de un paso. Si la nueva no arranca, la vieja sigue atendiendo sin cambios.

## Qué corre

El deployer corre `docker compose` desde la carpeta de cada release (`/opt/nexus/releases/<sha>/`), con `compose.yaml`, `compose.prod.yaml` y un `compose.release.yaml` que fija la imagen exacta de cada servicio. No hay que escribir comandos de Compose a mano: el deployer los da con `nexus compose …` (ver [Operación cotidiana](#operación-cotidiana)).

| Servicio | Qué hace | Se ve desde fuera |
|---|---|---|
| `caddy` | HTTPS (obtiene y renueva el certificado), redirige 80 a 443, sirve los assets compilados y reenvía al color sano | Sí: puertos 80, 443 TCP y 443 UDP |
| `app-blue`, `app-green` | Laravel + Octane, dos colores idénticos; uno atiende y el otro queda detenido con la versión anterior | No |
| `migrate` | Migraciones, de un solo uso, antes de arrancar el color nuevo | No |
| `queue` | Worker de colas (misma imagen que la app) | No |
| `scheduler` | Lo programado (`schedule:work`): la limpieza de exportes y los latidos de `/health` | No |
| `solver` | Motor de horarios | No |
| `postgres` | Base de datos (volumen `postgres-data`) | No |
| `valkey` | Caché, sesiones y colas (volumen `valkey-data`) | No |
| `backup` | `pg_dump` diario hacia `BACKUP_DIR` con retención, y el respaldo previo a cada migración | No |
| `deployer` | Despliega, vuelve atrás, pausa y reporta; revisa la carpeta de entrada cada minuto | No |

Los exportes de lo publicado (PDF, Excel y SII) viven en `storage/app/exports`, dentro del volumen `app-storage`. No hace falta respaldarlos: se vuelven a armar desde las versiones publicadas.

**Cabeceras de seguridad:** las pone la aplicación (middleware de Core, tarea 4.8), con o sin proxy. Caddy solo agrega HSTS, porque termina TLS, y quita `Server`.

Volúmenes de Docker: `postgres-data`, `valkey-data`, `app-storage` (archivos compartidos entre los colores, la cola y el scheduler), `build-assets` (los assets de los últimos releases), `caddy-data` (certificados) y `caddy-config`. **Nunca ejecutes `docker compose down -v` en producción**: borra la base de datos y los certificados.

Carpetas del servidor (`NEXUS_HOME=/opt/nexus`):

```
/opt/nexus/
├── .env                 secretos y ajustes (600)
├── inbox/               los paquetes se copian aquí; hechos/, fallidos/ y omitidos/ guardan lo procesado con su registro
├── releases/<sha>/      la infraestructura de cada release (se conservan DEPLOY_KEEP_RELEASES, 5)
├── config/              la configuración de Caddy y de los respaldos del release activo
└── .deploy/             estado del deployer: color activo, historial (releases.log), pausa, candado
```

## Requisitos del servidor

- Linux con Docker Engine y el plugin de Compose v2 (probado con Docker 29 y Compose 5). Nada más: el servidor no construye, no usa `git` ni necesita `jq`. El usuario que opera pertenece al grupo `docker`.
- Un nombre (`APP_DOMAIN`, `nexus.cdjuarez.tecnm.mx`) con registro DNS hacia la IP del servidor.
- HTTPS: Let's Encrypt necesita los puertos **80 y 443** abiertos desde internet y salida a `acme-v02.api.letsencrypt.org`. **Sin internet no funciona:** hace falta el certificado del ITCJ (plan B del ADR-0013) antes del arranque real.
- Reloj sincronizado (NTP).
- Docker arranca con el sistema (`sudo systemctl enable docker`). Los contenedores tienen `restart: unless-stopped`: tras un reinicio vuelve el color activo, y el detenido sigue detenido.
- Espacio: la base, los respaldos (14 diarios, 8 semanales y 5 previos a migraciones), las imágenes de los últimos 5 releases (cerca de 1 GB cada uno), los paquetes procesados de `inbox/hechos/` (bórralos de vez en cuando) y los registros (hasta 50 MB por servicio).

## Primer arranque (servidor sin internet)

1. **Descarga el paquete** en una máquina con acceso a GitHub: en el repositorio, *Actions* → *Deploy* → la corrida del último `main` → artefacto `nexus-<sha>` (trae el `.tar` y su `.sha256`). Verifícalo y cópialo al servidor por la red del Tec o en una memoria USB:

   ```bash
   sha256sum -c nexus-<sha>.tar.sha256
   scp nexus-<sha>.tar nexus-<sha>.tar.sha256 servidor:/tmp/
   ```

2. **Carpetas** en el servidor. `BACKUP_DIR` contendrá datos personales de alumnos y docentes:

   ```bash
   sudo install -d -o "$USER" -g "$USER" /opt/nexus /opt/nexus/inbox
   sudo install -d -o "$(id -u)" -g "$(id -g)" -m 700 /var/backups/nexus
   sudo install -d -o 1000 -g 1000 -m 750 /srv/nexus/fotos
   sudo install -d -o 1000 -g 1000 -m 750 /srv/nexus/fotos-personal
   ```

   **En un servidor que ya existía** (preparado antes de la tarea 1.35), `/srv/nexus/fotos-personal` no existe: créalo con dueño uid 1000 **antes** de desplegar este release (`sudo install -d -o 1000 -g 1000 -m 750 /srv/nexus/fotos-personal`); si no, Docker lo crea como `root` y Nexus no podrá escribir las fotos (la pantalla avisará «No se pudo guardar la foto»). Lo mismo con `/srv/nexus/fotos`, que desde 1.37 también se escribe: `sudo chown -R 1000:1000 /srv/nexus/fotos`.

   `fotos-personal` (`NEXUS_STAFF_PHOTOS_DIR`) guarda las fotos del personal que sube Comunicación (CO-03): Nexus las escribe, así que el contenedor (usuario `app`, uid 1000) debe poder escribir ahí. Son datos personales: inclúyela en el respaldo del servidor junto con `/srv/nexus/fotos`; nunca va al repositorio.

   `fotos` (`NEXUS_STUDENT_PHOTOS_DIR`) guarda las fotos de la credencial de los alumnos. Desde la tarea 1.37 (CO-06) Comunicación también las sube, así que el montaje ya no es de solo lectura y el contenedor (uid 1000) debe poder escribir ahí; una foto reemplazada pasa a `_anteriores/<AAAA>/` y no se borra. Si el directorio ya existía con otro dueño: `sudo chown -R 1000:1000 /srv/nexus/fotos`.

3. **El `.env`**, desde la plantilla que trae el paquete:

   ```bash
   cd /opt/nexus
   tar -xOf /tmp/nexus-<sha>.tar infra.tar | tar -xO .env.production.example > .env
   chmod 600 .env
   ```

   Edita `.env` y reemplaza todo lo que diga `CAMBIAR` o `example`:

   | Variable | Valor |
   |---|---|
   | `APP_DOMAIN` | `nexus.cdjuarez.tecnm.mx` |
   | `ACME_EMAIL` | Correo de TI que recibe los avisos de vencimiento |
   | `APP_KEY` | `echo "base64:$(openssl rand -base64 32)"` |
   | `DB_PASSWORD`, `REDIS_PASSWORD`, `HEALTH_TOKEN` | `openssl rand -hex 24` (sin `$` ni espacios) |
   | `SOLVER_TOKEN` | `openssl rand -hex 32` |
   | `ADMIN_INITIAL_PASSWORD` | `openssl rand -hex 12` (solo para el primer `db:seed`) |
   | `BACKUP_DIR`, `BACKUP_UID`, `BACKUP_GID` | `/var/backups/nexus`, `$(id -u)`, `$(id -g)` (con `0` los archivos quedan como `root`) |
   | `MAIL_FROM_ADDRESS`, `MICROSOFT_GRAPH_*` | La cuenta de envío y la app de Azure: [«Correo saliente (Microsoft 365)»](#correo-saliente-microsoft-365) |
   | `NEXUS_HOME`, `DEPLOY_*` | Déjalos como vienen (`/opt/nexus`, `DEPLOY_SOURCE=bundle`) |

   Guarda una copia del `.env` **fuera del servidor**, en un lugar con acceso restringido: sin `APP_KEY` no se pueden descifrar las sesiones ni los valores cifrados.

4. **Despliega el primer paquete.** El deployer viene dentro del paquete sin comprimir, así que basta `tar` y `docker`. Después se instala él mismo como servicio y atiende la carpeta de entrada:

   ```bash
   tar -xOf /tmp/nexus-<sha>.tar images/deployer.tar | docker load
   mv /tmp/nexus-<sha>.tar /tmp/nexus-<sha>.tar.sha256 /opt/nexus/inbox/
   docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v /opt/nexus:/opt/nexus \
     --env-file /opt/nexus/.env ghcr.io/erne1357/nexus-deployer:sha-<sha> \
     deploy --bundle /opt/nexus/inbox/nexus-<sha>.tar
   ```

   Tarda unos minutos: carga las imágenes, levanta PostgreSQL, Valkey y el motor, aplica las migraciones, arranca `app-blue`, la cola, el scheduler, los respaldos y Caddy. Termina con «listo: atiende <sha> en app-blue».

5. **El atajo `nexus`** para operar (agrégalo al `~/.bashrc` de quien opera):

   ```bash
   alias nexus='docker exec -it nexus-deployer-1 nexus-deploy'
   nexus status
   ```

6. **Datos base y primer administrador** (si no se arranca con la copia de la demo real, sección siguiente). Con `ADMIN_INITIAL_PASSWORD` en `.env`, el seeder crea los campus, el organigrama y el usuario `admin`; es idempotente y no toca la contraseña de un `admin` existente. El primer acceso obliga a cambiarla; después la variable ya no hace falta.

   ```bash
   nexus compose exec app-blue php artisan db:seed --force --no-interaction
   ```

7. **Verifica:** `nexus status` dice `ok` o `degraded` (degradado es normal el primer minuto, hasta el primer latido del scheduler); `curl -fsS https://nexus.cdjuarez.tecnm.mx/health` responde 200; el primer respaldo está en `/var/backups/nexus/daily`.

## Arrancar con la copia de la demo real (tarea 1.29)

Para empezar las pruebas sin esperar las horas de la migración del SII, el primer servidor carga una copia de la demo real en lugar del paso 6 (decisión del usuario del 22 de noviembre de 2026). La copia es un `.dump` (formato custom de `pg_dump`) que se entrega fuera del repositorio: **tiene datos personales** de alumnos y docentes, y se trata como los respaldos.

### Qué trae la copia

- Todo lo de la demo real: la migración del SII (v2, con el esquema crudo `sii_archivo`, las filas de importación y la verificación; la base va completa, unos 4.4 GB), el mapa del campus, los catálogos normalizados, las sesiones del SII de Ago-Dic 2026 (las de los demás periodos las trae el paso 6), la carga de ISC para Ene-Jun 2027 (50 grupos) y la validación de catálogos abierta.
- **Periodos:** los 150 que aparecen en los datos (1973 a 2027, con veranos), dados de alta con `core:sync-terms-from-data` (TP-01); Ene-Jun 2027 con el calendario TecNM 2026-2027: del 25 de enero al 28 de mayo.
- **Cuentas:**
  - `admin` con una contraseña nueva y cambio obligatorio al entrar.
  - Los jefes, los directivos y Subdirección (25) sin contraseña: entran por la invitación del admin a su correo institucional (CF-07), que necesita el correo saliente configurado (sección «Correo saliente (Microsoft 365)»).
  - Las cuentas de prueba `prueba.*` (coordinadores, JPD y administrativo) activas con una contraseña nueva común.
  - El alumno y el docente ficticios de la demo dados de baja, y la cuenta del egresado activada a mano para probar la credencial, otra vez inactiva.
  - Los alumnos vigentes entran con su número de control y su NIP del SII, como en la demo.
- Sin las propuestas de carrera de prueba.

Las contraseñas nuevas se entregan aparte, en un archivo privado; no viajan en el `.dump` ni en el repositorio.

### Pasos

1. Pasos 1 a 5 del primer arranque. El paso 6 (`db:seed`) **no** se corre.
2. Copia el `.dump` al servidor, dentro de `BACKUP_DIR` (es confidencial):

   ```bash
   scp nexus_prod-AAAAMMDD.dump servidor:/var/backups/nexus/daily/
   ssh servidor chmod 600 /var/backups/nexus/daily/nexus_prod-AAAAMMDD.dump
   ```

3. Reemplaza la base recién creada con la copia (es el procedimiento B de «Restaurar un respaldo»). La copia pesa varios GB: tarda unos minutos. Después, vuelve a desplegar el mismo release: el deployer aplica las migraciones que la copia no tenga (con su respaldo previo) y arranca la app.

   ```bash
   ARCHIVO=nexus_prod-AAAAMMDD.dump
   nexus pause "restaurando la copia de la demo real"
   nexus compose stop app-blue app-green queue scheduler
   nexus compose exec backup sh -c "dropdb --force \"\$PGDATABASE\" && createdb \"\$PGDATABASE\" && pg_restore --no-owner --exit-on-error --single-transaction -d \"\$PGDATABASE\" /backups/daily/$ARCHIVO"
   nexus deploy --sha <sha> --force
   nexus resume
   ```

4. **Fotos de las credenciales.** No viven en la base. Copia la carpeta de fotos de la demo (`storage/app/fotos`, con la forma `<AAAA>/<número de control>.jpg`) a `/srv/nexus/fotos` (o a `NEXUS_STUDENT_PHOTOS_DIR`), sin los archivos `Zone.Identifier` que deja Windows. `compose.prod.yaml` la monta con escritura (CO-06; el contenedor debe poder escribir, ver la preparación de directorios):

   ```bash
   rsync -a --exclude='*Zone.Identifier' fotos/ servidor:/srv/nexus/fotos/
   sudo chown -R 1000:1000 /srv/nexus/fotos /srv/nexus/fotos-personal
   ```

5. **Variables.** `APP_URL` sale de `APP_DOMAIN` (`nexus.cdjuarez.tecnm.mx`); `NEXUS_CREDENTIAL_URL` va vacía en producción (el QR usa `APP_URL`; en la demo apuntaba al túnel); `NEXUS_DEFAULT_PASSWORD` solo hace falta al correr `core:provision-staff` (1.39; ver el punto de `riel/comunicacion` en «Qué hacer al desplegar cada release»); el personal que no se activa así entra por invitación. `NEXUS_INSTITUTIONAL_EMAIL_DOMAINS` (1.63, CE-01) es la lista, separada por comas, de los dominios a los que Nexus sí manda correo; por omisión `cdjuarez.tecnm.mx`, que es lo que va en producción (`itcj.edu.mx` no cuenta), y lo que salga a otro dominio sin una excepción declarada queda «Bloqueado» en «Correos y colas». El correo, en «Correo saliente (Microsoft 365)».
6. **Horarios del SII de todos los periodos** (tarea 1.32: el mapa del Inicio sigue el periodo de la credencial y necesita los horarios de cada uno). Una vez, en el color activo; tarda alrededor de un minuto (40 periodos, unas 315 000 sesiones) y se puede repetir:

   ```bash
   nexus compose exec app-<color> php artisan academic-load:import-sii-groups --no-interaction
   ```

7. **Verificar:**
   - `nexus status` y `curl -fsS https://nexus.cdjuarez.tecnm.mx/health` responden sano.
   - Entra como `admin` con la contraseña entregada; Nexus pide cambiarla.
   - En Configuración → Correos y colas, conecta la cuenta de envío y manda el correo de prueba.
   - Captura el correo institucional de cada jefe en el panel de usuarios y mándale su invitación.
   - Revisa el mapa del inicio, la credencial de un alumno (foto y QR) y el selector de periodo, que ofrece desde 2020.
8. Haz un respaldo manual: `nexus compose exec backup sh /opt/backup/backup.sh run`.

## Actualizar a una versión nueva

**Sin internet (hoy):** descarga el artefacto `nexus-<sha>` de la corrida de *Deploy* del `main` que quieres, verifica el `sha256` y copia **los dos archivos** a la carpeta de entrada. Copia primero el `.tar` y al final el `.sha256`: el deployer solo toma un paquete con su `.sha256` y cuyo tamaño ya no cambia.

```bash
sha256sum -c nexus-<sha>.tar.sha256
scp nexus-<sha>.tar servidor:/opt/nexus/inbox/
scp nexus-<sha>.tar.sha256 servidor:/opt/nexus/inbox/
```

En menos de un minuto el deployer lo verifica y lo despliega sin cortar el servicio. Para seguirlo: `docker logs -f nexus-deployer-1`. Al terminar, el paquete queda en `inbox/hechos/` (o en `inbox/fallidos/`, con `<paquete>.log` explicando por qué). Si hay varios paquetes, despliega el más nuevo y aparta los demás en `inbox/omitidos/`. También se puede a mano: `nexus deploy --bundle /opt/nexus/inbox/nexus-<sha>.tar`.

**Con internet:** pon `DEPLOY_SOURCE=github` en `.env` y crea `/opt/nexus/deployer.env` (`chmod 600`) con `DEPLOY_GITHUB_TOKEN=<token>`: un token *fine-grained* del repositorio con permiso de lectura y escritura solo en *Deployments*. Va aparte porque el `.env` lo leen también la app y la cola; `deployer.env` solo el deployer. Después, `nexus compose up -d --no-deps deployer`. Desde ahí no hay que hacer nada: cada merge a `main` se despliega solo y el resultado se ve en *Environments → production* del repositorio. El deployer solo acepta imágenes `ghcr.io/erne1357/nexus-*` por digest. Si el servidor sale por el proxy del ITCJ, pon `HTTPS_PROXY` y `NO_PROXY` en `deployer.env` y configura también el proxy del daemon de Docker (`/etc/systemd/system/docker.service.d/http-proxy.conf`), que es quien descarga las imágenes.

Qué hace el deployer, en orden:

1. Verifica el paquete (`sha256`), carga las imágenes y deja la infraestructura del release en `releases/<sha>/`.
2. Revisa el `.env`: si el release trae variables nuevas en `.env.production.example` que tu `.env` no tiene, **no despliega** y las lista (código 5). Agrégalas y vuelve a copiar el paquete (o `nexus deploy --sha <sha>`).
3. Si hay migraciones pendientes: respaldo previo en `BACKUP_DIR/predeploy/` y `migrate` con la imagen nueva. La versión vieja sigue atendiendo: las migraciones son compatibles con ella (expand/contract, CLAUDE.md §5).
4. Arranca el color inactivo con la versión nueva, **drenado** (Caddy todavía no le manda tráfico), y espera a que `/health` responda sano con ese release (hasta `DEPLOY_HEALTH_TIMEOUT`, 180 s). Si no llega, lo detiene y **producción sigue igual** (código 6).
5. Recrea la cola y el scheduler con la versión nueva; pone el color nuevo en la rotación; drena el viejo y lo detiene con apagado ordenado. El viejo queda detenido para volver atrás al instante.
6. Verifica por Caddy que atiende la versión nueva; si no, vuelve sola a la anterior (código 8).
7. Registra el release. Si el Caddyfile cambió, lo valida con la imagen de Caddy y lo recarga sin cortar conexiones (si no es válido, Caddy sigue con el anterior y queda un aviso). Limpia lo que pasó de los últimos 5 y, si cambió, se actualiza a sí mismo cuando termina cualquier despliegue en curso.

### Comandos de una sola vez después de un release

El despliegue automático solo hace lo que es idempotente y seguro mientras conviven dos versiones (migraciones expand/contract). Los ajustes de datos de una sola vez (reimportar algo, derivar datos nuevos, corregir o desactivar registros reales) **no** van en una migración, ni en un seeder, ni en el deployer. Son comandos de Artisan que traen `--dry-run` (solo cuenta) y que toman sus datos de un archivo puesto en el servidor, fuera del repositorio, o de sus argumentos; el repositorio es público (decisión del usuario, 24 de noviembre de 2026; CLAUDE.md §5). Se corren a mano, una vez, en el color activo, cuando el release que los necesita ya atiende:

```bash
nexus status                                                   # dice el color activo (blue o green)
nexus compose exec app-<color> php artisan <comando> --no-interaction
```

Con un archivo de datos: usa el color activo (`nexus status`). Con `docker exec -i` el archivo queda del usuario `app` de la imagen, y la carpeta se crea si no existe:

```bash
C=nexus-app-<color>-1
nexus compose exec backup sh /opt/backup/backup.sh run              # 1. respaldo (daily/nexus-<fecha-hora>.dump; no toca predeploy/)
docker exec -i $C sh -c 'mkdir -p storage/app/imports && cat > storage/app/imports/<archivo>' < <archivo>   # 2. subir el archivo
nexus compose exec app-<color> php artisan <comando> storage/app/imports/<archivo> --dry-run --no-interaction   # 3. solo cuenta
nexus compose exec app-<color> php artisan <comando> storage/app/imports/<archivo> --no-interaction             # 4. de verdad
docker exec $C rm storage/app/imports/<archivo>                     # 5. borrar el archivo del servidor
```

Nada de catálogo ni de bitácora se borra físicamente: se desactiva.

Cada riel que traiga uno lo anota aquí: el release (o PR) a partir del cual aplica, el orden, si se puede repetir y cuánto tarda. Lo que tarde más de unos segundos va en un job de la cola (CLAUDE.md §4.7).

| Desde | Comando | Orden | ¿Se puede repetir? | Tiempo |
|---|---|---|---|---|
| 1.32 (#824) y otra vez desde 1.33 (D-01: la sesión sin RFC toma el del grupo) | `php artisan academic-load:import-sii-groups` (todos los periodos; reemplaza el historial de grupos `sii` y sus horarios desde `sii_archivo`) | Una vez después del release que trae 1.33, y después de restaurar una copia de la demo o de reimportar el SII | Sí (idempotente) | ~1 min |
| `riel/orden-seleccion` (1.33) | `php artisan faculty:derive-qualifications` (D-02: perfil «Apto» de los últimos 6 semestres) y después `php artisan staffing:rank <periodo>` (p. ej. `staffing:rank 20271`; opcional `--modality=` y `--department=`), que recalcula el ranking docente con el perfil nuevo | Después de importar los grupos; el ranking, justo después de derivar el perfil | Sí, los dos | segundos el perfil; el ranking, en el momento y sin la cola (unos segundos por departamento) |
| `riel/orden-seleccion` (1.33) | `php artisan facilities:set-room-types C1 701=sala_computo 703=sala_computo 705=sala_computo 706=sala_computo 702=laboratorio 707=laboratorio` (tipos de los salones de Sistemas) | Después de derivar el perfil | Sí | segundos |
| `riel/comunicacion` (1.39) | `php artisan core:provision-staff storage/app/imports/comunicacion/<archivo>.csv` (CSV `usuario,nombre,foto`, con las fotos junto al CSV; primero `--dry-run`). Activa las cuentas del personal con la contraseña por defecto y cambio obligatorio, corrige nombres y pone fotos (CO-11). Es la excepción a AC-03: el panel ya no da esa contraseña. Requiere `NEXUS_DEFAULT_PASSWORD` en el `.env` | Después del release que trae 1.35 y 1.39; subir el CSV y las fotos con el procedimiento de arriba y borrarlos después | Sí (idempotente: no toca a quien ya eligió su contraseña ni repite fotos iguales) | segundos |
| `riel/carga-sistemas` (1.40) | `php artisan academic-load:import-projection storage/app/imports/carga-sistemas/<relacion>.csv --term=20271 --campus=C1 --modality=PRES --user=<username del admin>`, primero con `--dry-run` (carga de Sistemas Ene-Jun 2027 desde la «Relación de materias»; el archivo lo pone el operador en el servidor) | Después del release que trae 1.40 y del respaldo diario manual | Sí (idempotente: lo que ya existe no se duplica) | segundos |
| `riel/carga-sistemas` (1.41) | `php artisan staffing:import-prior-choices storage/app/imports/carga-sistemas/<relacion>.csv --term=20271 --campus=C1 --modality=PRES --user=<username del admin>`, primero con `--dry-run` (elección previa de los maestros escritos en la relación y tipo de espacio vacío de las materias) | Justo después del anterior, con el mismo archivo, y **antes** de publicar la orden de selección (con la selección publicada se rechaza) | Sí (idempotente) | segundos |
| `riel/alumnos` (1.69) | `php artisan academic:mark-current-specialties`, primero con `--dry-run` (activa las especialidades de `periodo_inicio` más reciente de cada plan según `sii_archivo.especialidades`, desactiva las demás del plan y no toca las que el SII no lista; imprime el resumen por plan) | Después del release; antes del siguiente | Sí | segundos |
| `riel/alumnos` (1.69) | `php artisan students:sync-sii-specialties`, primero con `--dry-run` (llena `students.specialty_id` de los alumnos ya importados con `sii_archivo.alumnos_todos.especialidad`; las claves que no existen en el plan del alumno se informan y quedan sin especialidad). Al terminar de verdad encola el recálculo del avance (`RecomputeStudentProgress`, necesita la cola) | Después de `academic:mark-current-specialties` | Sí | un minuto o dos |
| `riel/alumnos` (1.63b, CE-02) | `php artisan academic:position-mailboxes-from-departments --user=<username del admin>`, primero con `--dry-run`. Crea el buzón de la jefatura (`jefe_departamento` sobre la unidad del departamento) de cada departamento activo que ya trae su correo institucional en `departments.email`; no toca una jefatura que ya tiene buzón activo y lo reporta. Al crear cada uno salen en la cola `bulk` los correos de ese puesto que esperaban (y el scheduler vuelve a revisar cada 15 minutos, `release-held-position-mails`). Los demás buzones (coordinaciones, jefes de proyecto docente, Servicios Escolares si no trae correo) se capturan en Configuración › «Buzones de puesto» | Después del release que trae 1.63b; mientras no se corra, los avisos de las jefaturas llegan a la campana y su correo espera | Sí (idempotente) | segundos |

### Pausar los despliegues

Para congelar en temporadas sensibles (validación, inscripciones): `nexus pause "inscripciones"`. Mientras tanto los paquetes que lleguen esperan en `inbox/` y los despliegues de GitHub siguen en cola; `nexus resume` los reanuda (se despliega el más nuevo). Con internet, la variable del repositorio `DEPLOY_PAUSED=true` (*Settings → Secrets and variables → Actions → Variables*) hace que `deploy.yml` deje de crear despliegues, aunque siga publicando imágenes y paquetes. Volver atrás funciona aunque haya pausa.

### Volver a la versión anterior

```bash
nexus rollback                 # al release anterior
nexus rollback <sha>           # a uno en particular (de los últimos 5; basta el inicio del sha)
```

También sin entrar al servidor: un archivo vacío `rollback-previous` o `rollback-<sha>` en `inbox/`, o, con internet, *Actions → Deploy → Run workflow* con `rollback`.

- Usa las imágenes exactas de ese release, sin descargar nada, y el mismo cambio de color sin caídas. Si el contenedor detenido ya es esa versión, solo lo enciende.
- No revierte migraciones: el esquema nuevo es compatible con el código viejo. `/health` dirá `degraded` («la base tiene migraciones que el código no conoce»), que es lo esperado.
- **Si ya había nombramientos `personal`** (el puesto «Personal de área», migración `2026_11_24_000001_add_personal_role`, tarea 1.34), el código anterior a 1.34 no conoce ese valor: los `casts` de `RoleAssignment.role` y `User.last_role` lanzan `ValueError` en «Personas y puestos», en el panel del organigrama de Comunicación y al iniciar sesión esas personas. Antes de volver a una versión previa a 1.34, termina esos nombramientos (desde «Personas y puestos» con el release actual, o con `delete from role_assignments where role = 'personal'`) y pon en `null` el `users.last_role` que valga `personal` (`update users set last_role = null where last_role = 'personal'`); o restaura el respaldo previo a migrar (`--restore`).
- **Si de por medio se aplicó una contracción** (una migración con `#[Contract]` que borró o renombró columnas o tablas, aunque el despliegue que la trajo haya fallado después), el código viejo ya no funcionaría con el esquema y el deployer se niega (código 7) diciendo qué respaldo habría que restaurar. Lo mismo con `nexus deploy --sha <un release anterior>`. Con `nexus rollback <sha> --restore --yes`:
  1. verifica que el respaldo exista y se pueda leer (si no, no toca nada);
  2. pone la app en mantenimiento y restaura el respaldo en una base aparte;
  3. solo si salió bien, la cambia por la real; la de antes queda como `nexus_antes_<fecha>` para revisarla (bórrala con `nexus compose exec postgres dropdb -U nexus nexus_antes_<fecha>` cuando ya no la necesites; ocupa lo mismo que la base);
  4. vacía Valkey (cierra las sesiones y vacía la cola) y despliega el destino.

  **Se pierde todo lo escrito desde ese respaldo.** Hace falta espacio en disco para una segunda copia de la base mientras dura.

### `/health`

`https://nexus.cdjuarez.tecnm.mx/health` responde JSON: `status` (`ok`, `degraded`, `draining` o `fail`), `release` (el SHA que atiende) y el estado de cada chequeo. 200 si atiende (`ok` o `degraded`), 503 si no.

| Chequeo | Si falla |
|---|---|
| `database`, `cache`, `queue`, `storage` | `fail` (503) |
| `migrations` | pendientes o con nombre repetido: `fail`; en la base y no en el código (tras volver atrás): `degraded` |
| `solver`, `queue_worker`, `scheduler` | `degraded` (200): la app atiende, pero el motor, la cola o lo programado no responden |

Sin la cabecera `X-Health-Token: <HEALTH_TOKEN>` solo salen los estados; con ella, el detalle (errores, migraciones, tamaños de las colas). `nexus status` lo muestra con detalle. `/up` sigue existiendo: solo dice que el proceso vive.

## Respaldos

El servicio `backup` hace un `pg_dump` en formato custom (`-Fc`, comprimido) a las 03:00 hora de Ciudad Juárez y lo valida antes de dejarlo. Estructura de `BACKUP_DIR`:

```
/var/backups/nexus/
├── daily/      nexus-20261027-030000.dump            (últimos 14)
├── weekly/     nexus-2026-W44.dump                   (últimas 8; la copia del domingo)
└── predeploy/  nexus-20261123-101500-1a2b3c4d5e6f.dump   (últimos 5; antes de cada migración)
```

- Si al arrancar no hay un respaldo de menos de 26 horas (primer despliegue, servidor apagado a las 03:00), respalda de inmediato.
- El contenedor pasa a `unhealthy` si el último respaldo diario tiene más de 26 horas: revísalo con `nexus compose ps`.
- Respaldo manual: `nexus compose exec backup sh /opt/backup/backup.sh run`.
- **Respaldo previo a migrar:** antes de aplicar las migraciones de un release, el deployer deja `predeploy/nexus-<fecha>-<sha corto>.dump` (se conservan los últimos `BACKUP_KEEP_PREDEPLOY`, 5, fuera de la rotación diaria). Lo usa el rollback con `--restore`.
- Ajustes en `.env`: `BACKUP_TIME`, `BACKUP_KEEP_DAILY`, `BACKUP_KEEP_WEEKLY`, `BACKUP_KEEP_PREDEPLOY`.
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
nexus compose exec backup sh -c "createdb nexus_restore && pg_restore --no-owner --exit-on-error --single-transaction -d nexus_restore /backups/daily/$ARCHIVO"
nexus compose exec backup psql -d nexus_restore -c 'select count(*) from users'
nexus compose exec backup psql -d nexus_restore -c 'select count(*) from migrations'
nexus compose exec backup dropdb nexus_restore
```

Compara los conteos con la base real (`-d nexus`). `--single-transaction` y `--exit-on-error` hacen que, si algo falla, la base nueva quede vacía en lugar de a medias.

### B. Recuperar producción desde un respaldo

Reemplaza la base real: todo lo escrito después del respaldo se pierde. Si es para deshacer un release, primero prueba `nexus rollback` (con `--restore` si hubo una contracción): hace esto mismo con el respaldo correcto.

```bash
nexus pause "restauración"
nexus compose stop app-blue app-green queue scheduler
nexus compose exec backup sh -c "dropdb --force \"\$PGDATABASE\" && createdb \"\$PGDATABASE\" && pg_restore --no-owner --exit-on-error --single-transaction -d \"\$PGDATABASE\" /backups/daily/$ARCHIVO"
nexus deploy --sha <sha del release que corresponde a ese respaldo> --force
nexus resume
```

Si restauras un respaldo anterior a una migración, despliega el release que coincide con ese esquema: uno más nuevo aplicaría las migraciones pendientes (con su respaldo previo).

### Servidor perdido

En un servidor nuevo: pasos 1 a 5 del primer arranque (con el `.env` guardado fuera del servidor), copia el archivo `.dump` más reciente a `BACKUP_DIR/daily/` y aplica el procedimiento B.

## Correo saliente (Microsoft 365)

Nexus manda su correo (invitaciones de acceso, restablecer la contraseña, avisos) por Microsoft 365 con Microsoft Graph y **permisos delegados** ([ADR-0032](../decisiones/0032-correo-por-microsoft-graph.md)): sale como la cuenta que el admin conecta en Nexus, que debe ser la del remitente. Conviene un buzón institucional de servicio (por ejemplo `nexus@cdjuarez.tecnm.mx`) y no la cuenta de una persona: si esa cuenta cambia de contraseña o la dan de baja, hay que reconectar. El servidor necesita salida HTTPS a `login.microsoftonline.com` y `graph.microsoft.com`.

### 1. Registrar la app en Azure

En [portal.azure.com](https://portal.azure.com) → **Microsoft Entra ID** → **App registrations** → **New registration**:

1. **Name:** `Nexus` (o el que prefieras).
2. **Supported account types:** *Accounts in this organizational directory only* (un solo tenant).
3. **Redirect URI:** plataforma **Web**, con
   `https://nexus.cdjuarez.tecnm.mx/configuracion/correo/microsoft/callback`
   (es `APP_URL` + `/configuracion/correo/microsoft/callback`; Nexus la muestra en la tarjeta «Cuenta de envío». Si pruebas en otro dominio, agrega también esa).
4. **Register.**

Luego, en la app:

- **Overview:** copia **Directory (tenant) ID** y **Application (client) ID**. El tenant ID es el del ITCJ: Nexus no acepta `common`, `organizations` ni `consumers`.
- **Certificates & secrets** → **Client secrets** → **New client secret**: elige la duración y copia el **Value** (no el *Secret ID*); solo se muestra una vez. **Anota la fecha en que vence:** antes de esa fecha crea otro, ponlo en `.env` y vuelve a desplegar el release activo (`nexus deploy --sha <sha activo> --force`). Si vence, el correo deja de salir y la tarjeta dice que se revise `MICROSOFT_GRAPH_CLIENT_SECRET`.
- **API permissions** → **Add a permission** → **Microsoft Graph** → **Delegated permissions**: `Mail.Send`, `User.Read` y `offline_access` (están en *OpenId permissions*). Si la política del tenant no deja que los usuarios den consentimiento, un administrador del tenant pulsa **Grant admin consent for <tenant>**.

### 2. Variables del `.env`

```bash
MAIL_MAILER=microsoft-graph
MAIL_FROM_ADDRESS="nexus@cdjuarez.tecnm.mx"     # la misma cuenta que conectarás en Nexus
MAIL_FROM_NAME="${APP_NAME}"
MICROSOFT_GRAPH_TENANT_ID=<Directory (tenant) ID>
MICROSOFT_GRAPH_CLIENT_ID=<Application (client) ID>
MICROSOFT_GRAPH_CLIENT_SECRET=<Value del client secret>
```

y vuelve a desplegar el release activo (`nexus deploy --sha <sha activo> --force`) para que la app, `queue` y `scheduler` las tomen. `APP_URL` sale de `APP_DOMAIN` (`compose.prod.yaml`), así que `APP_DOMAIN=nexus.cdjuarez.tecnm.mx` debe coincidir con la Redirect URI. El nombre que ve quien recibe es el del buzón en Microsoft 365, no `MAIL_FROM_NAME`.

### 3. Conectar la cuenta en Nexus

1. Entra como admin → **Configuración** → **Correos y colas**. La tarjeta **Cuenta de envío** dice si falta alguna variable y muestra la dirección de regreso.
2. **Conectar con Microsoft** e inicia sesión con la **cuenta de envío** (el remitente), no con la tuya si son distintas; acepta los permisos.
3. De regreso, la tarjeta dice «Conectada» y la cuenta. Pulsa **Enviar correo de prueba**: llega a tu correo (o, si tu usuario no tiene, a la misma cuenta) y queda en la pestaña Correos.

### Mantenimiento

- **Ritmo:** Exchange Online deja mandar unos 30 correos por minuto por buzón. Nexus manda a lo más 5 cada 15 s; un envío grande (invitaciones de la validación, avisos de la publicación) se reparte solo en la cola, y si Microsoft frena (429), la cola espera y reintenta. Desde una pantalla (el correo de prueba, por ejemplo) no se espera: sale el aviso «intenta en un minuto».

- **La conexión venció** (la tarjeta dice «Venció»: Microsoft ya no la renueva porque cambió la contraseña, se revocaron las sesiones o lo pidió una política): **Reconectar con Microsoft** con la misma cuenta. Los correos que fallaron mientras tanto están en la pestaña Colas, con **Reintentar**.
- **Cambiar de cuenta de envío:** **Desconectar** y **Conectar con Microsoft** con la otra (o conectar directamente: reemplaza la anterior). Cambia también `MAIL_FROM_ADDRESS`.
- **Cambiar `APP_KEY`** deja ilegibles los tokens guardados: hay que reconectar.
- **Desconectar** borra la conexión en Nexus, pero no revoca nada en Microsoft: para eso se quita el consentimiento de la app en Microsoft Entra (**Enterprise applications** → la app → **Permissions**) o en «Mis aplicaciones» de la cuenta.

## Certificados HTTPS

- Caddy obtiene el certificado la primera vez que arranca y lo renueva solo, unos 30 días antes de que venza. No hay nada que programar.
- Los certificados viven en el volumen `caddy-data`. No lo borres: Let's Encrypt limita los certificados repetidos para el mismo nombre.
- Para ver el estado: `nexus compose logs caddy | grep -E 'tls|error'`.
- Mantén abiertos los puertos 80 y 443 y el DNS apuntando al servidor, o la renovación fallará. Let's Encrypt avisa por correo a `ACME_EMAIL` cuando un certificado está por vencer sin renovarse.
- Si el ITCJ no puede abrir los puertos o prefiere su propio certificado, el plan B (certificado entregado por el ITCJ o desafío DNS) está descrito en el ADR-0013.

## Operación cotidiana

`nexus` es el atajo del primer arranque (`docker exec -it nexus-deployer-1 nexus-deploy`); `nexus compose …` es `docker compose` del release activo.

```bash
nexus status                               # color activo, release, /health con detalle, pausa y últimos despliegues
nexus compose ps                           # estado y salud de los servicios
nexus compose logs -f --tail 100 app-blue  # también: app-green, queue, scheduler, caddy, backup, postgres
docker logs -f nexus-deployer-1            # lo que hace el deployer
nexus compose exec app-blue php artisan queue:failed     # jobs fallidos (usa el color activo)
nexus compose exec app-blue php artisan queue:retry all
docker system df                           # espacio usado por Docker
```

Cambiar una variable del `.env` se aplica volviendo a desplegar el release activo (`nexus deploy --sha <sha activo> --force`): arranca el otro color con el `.env` nuevo, sin caída. Dos casos especiales:

- **`DB_PASSWORD`:** PostgreSQL solo lee la contraseña inicial al crear el volumen. Primero cámbiala en la base y luego en `.env`, y vuelve a desplegar:

  ```bash
  nexus compose exec postgres psql -U nexus -c "ALTER USER nexus PASSWORD 'NUEVA_CONTRASEÑA'"
  sed -i 's/^DB_PASSWORD=.*/DB_PASSWORD=NUEVA_CONTRASEÑA/' /opt/nexus/.env
  nexus deploy --sha <sha activo> --force
  ```

- **`REDIS_PASSWORD`:** cambiarla recrea `valkey` (un corte de segundos); las sesiones y la cola se conservan en el volumen `valkey-data`.

## Si algo falla

Códigos de salida del deployer (también en `inbox/fallidos/<paquete>.log` y en el estado del despliegue de GitHub):

| Código | Qué pasó | Qué hacer |
|---|---|---|
| 3 | Despliegues en pausa | `nexus resume`, o `--force` |
| 4 | El paquete no coincide con su `sha256` o no se pudo cargar | Vuelve a descargarlo y copia el `.tar` completo antes que el `.sha256` |
| 5 | El `.env` no trae variables que pide el release | Cópialas de `releases/<sha>/.env.production.example`, ponles valor y `nexus deploy --sha <sha>` |
| 6 | La versión nueva no quedó sana; producción sigue con la anterior | `inbox/fallidos/<paquete>.log` trae el último `/health`; `nexus compose logs app-<color nuevo>` |
| 7 | Volver atrás cruza una contracción | Lee el mensaje: dice qué respaldo restaurar. Repite con `--restore --yes` si aceptas perder lo escrito desde entonces |
| 8 | Después del cambio Caddy no atendía la versión nueva; se volvió sola a la anterior | `nexus compose logs caddy` |
| 9 | Una migración falló (o el respaldo previo) | La versión anterior sigue atendiendo. Lee el error en el registro; un `lock_timeout` se resuelve reintentando en un momento con menos uso (el reintento reusa el respaldo previo de ese release) |
| 10 | Otro despliegue seguía en curso | Espera a que termine (`docker logs -f nexus-deployer-1`) |

| Síntoma | Qué revisar |
|---|---|
| El sitio no abre o muestra «Nexus está en mantenimiento» | Caddy no encuentra ningún color sano: `nexus status`, `nexus compose ps`; si ninguno corre, `nexus deploy --sha <sha activo> --force` |
| Error de certificado en el navegador | `nexus compose logs caddy \| grep -E 'tls.obtain\|error'`. "could not get certificate from issuer" trae el motivo: tiempo de espera (el 80 o el 443 no llegan al servidor), nombre que no resuelve (DNS), sin salida a internet, o límite de certificados excedido |
| `/health` dice `degraded` | Mira qué chequeo con `nexus status`: `migrations` tras volver atrás es lo esperado; `scheduler` o `queue_worker` = el servicio no corre (`nexus compose ps`); `solver` = el motor no responde |
| Un paquete no se despliega | ¿Están el `.tar` y el `.sha256` en `inbox/`? ¿Hay pausa (`nexus status`)? ¿Está en `fallidos/` con su `.log`? |
| `backup` aparece `unhealthy` | `nexus compose logs backup`. Causas comunes: `BACKUP_DIR` sin permiso de escritura para `BACKUP_UID`, disco lleno o `postgres` caído. Prueba con `nexus compose exec backup sh /opt/backup/backup.sh run` |
| Las tareas en cola no avanzan | `nexus compose logs queue`; `nexus compose exec app-blue php artisan queue:failed` |
| No llegan los correos | Configuración → Correos y colas: la tarjeta «Cuenta de envío» dice si faltan variables, si no hay cuenta, si venció o el último error de Microsoft; los correos que fallaron están en la pestaña Colas. Ver «Correo saliente (Microsoft 365)». Sin internet no sale correo |
| Disco lleno | `docker system df`; borra paquetes viejos de `inbox/hechos/` y `inbox/omitidos/`; baja `DEPLOY_KEEP_RELEASES`; revisa el tamaño de `BACKUP_DIR` |
| Sesiones cerradas de repente | Cambió `APP_KEY`, se perdió el volumen `valkey-data`, o hubo un rollback con `--restore` |

## Mostrar la prueba de concepto

Mientras no haya servidor, el stack de producción corre en una máquina del equipo y se muestra por un túnel (Cloudflare Tunnel, ngrok, etc.). Un túnel público sin contraseña expone la aplicación a cualquiera con el enlace, así que Caddy ofrece una contraseña previa al sitio (`basic_auth`), apagada por defecto:

```bash
# Hash bcrypt de la contraseña (pide la contraseña dos veces; no queda en el historial)
nexus compose exec caddy caddy hash-password
```

En `.env` agrega el usuario y el hash **entre comillas simples** (el hash lleva `$`, que compose interpretaría en otro caso) y recrea Caddy:

```bash
BASIC_AUTH_USER=demo
BASIC_AUTH_HASH='$2a$14$...'
```
```bash
nexus compose up -d --no-deps caddy
```

Antes de recrear Caddy, valida la configuración en los dos casos (apagada y encendida):

```bash
docker run --rm --network none -v $PWD/docker/caddy:/etc/caddy:ro -e APP_DOMAIN=nexus.local -e ACME_EMAIL=a@b.c \
  caddy:2.11.7-alpine caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
docker run --rm --network none -v $PWD/docker/caddy:/etc/caddy:ro -e APP_DOMAIN=nexus.local -e ACME_EMAIL=a@b.c \
  -e BASIC_AUTH_USER=demo -e BASIC_AUTH_HASH="$(docker run --rm caddy:2.11.7-alpine caddy hash-password --plaintext prueba)" \
  caddy:2.11.7-alpine caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
```

Las dos deben decir «Valid configuration». Caddy prepara `basic_auth` aunque esté apagada; por eso el valor de respaldo del hash es `b2Zm` («off» en base64), y no `off`, que no lo deja arrancar.

Sin credenciales todas las rutas responden 401, salvo `/health` (el deployer lo usa para verificar cada despliegue); con ellas, la aplicación (que además pide su propio inicio de sesión). Para apagarla, comenta las dos líneas y repite `up -d caddy`. El túnel debe apuntar a `https://127.0.0.1:<HTTPS_PORT>` con la verificación del certificado desactivada (CA interna con `local_certs`) y enviando el nombre `APP_DOMAIN` como Host/SNI. Comparte la contraseña por un canal distinto al del enlace y baja el túnel al terminar. En el servidor real déjala apagada.

## Probar el stack de producción en una máquina de desarrollo

`.github/scripts/prod-smoke.sh` es la misma prueba que corre el CI (`prod-smoke`): levanta el stack de producción con el deployer real en un proyecto aparte y recorre los escenarios con tráfico continuo. **No toca el stack de desarrollo** (proyecto propio y puertos 18080/18443). Necesita las imágenes construidas y su paquete:

```bash
SHA=$(git rev-parse HEAD); R=ghcr.io/erne1357
docker build --target prod --build-arg NEXUS_RELEASE=$SHA -t $R/nexus-app:sha-$SHA .
docker build --target runtime -t $R/nexus-solver:sha-$SHA services/solver
docker build -t $R/nexus-deployer:sha-$SHA docker/deployer
docker/deployer/bin/nexus-bundle --sha $SHA --app $R/nexus-app:sha-$SHA --solver $R/nexus-solver:sha-$SHA \
  --deployer $R/nexus-deployer:sha-$SHA --repo . --out ~/nexus-smoke/bundle
.github/scripts/prod-smoke.sh --bundle ~/nexus-smoke/bundle/nexus-$SHA.tar --project nexus-smoke-local --home ~/nexus-smoke/run
```

Necesita `jq`, `zstd` y `curl` (o córrelo dentro de la imagen del deployer, que los trae) y unos 3 GB de disco: no uses un `/tmp` en memoria. Al terminar borra sus contenedores, volúmenes y carpeta (`--keep` los deja para revisar); `--deployer-src docker/deployer` monta el código local del deployer para iterar sin rearmar el paquete. `--restart-daemon` reinicia Docker para probar el arranque tras un reinicio: solo en el CI, nunca en una máquina compartida. Las pruebas de la configuración están en `tests/Unit/Deploy/ProductionStackTest.php`.

## Ensayo local (2026-11-10)

Despliegue limpio con el overlay de producción en un proyecto aparte (`-p nexus-prodtest`, Caddy en 18080/18443, `local_certs`, `.env` generado con secretos aleatorios, imagen construida desde el árbol de trabajo). Tiempos medidos en la máquina de desarrollo (la imagen ya tenía caché de capas):

| Paso | Resultado | Tiempo |
|---|---|---|
| `docker compose build app` | imagen `prod` | 22 s |
| `up -d --wait` desde cero | 6 servicios sanos; 89 migraciones aplicadas al arrancar; primer respaldo (vacío) | 19 s |
| `db:seed --force` | campus, organigrama y `admin` | 1 s |
| `GET /login` por HTTPS | 200, HTTP/2, `Strict-Transport-Security`, `X-Content-Type-Options`, `Referrer-Policy`, sin `Server`; HTTP responde 308 | 0,12 s |
| Cola | un job encolado lo procesó `queue` (DONE en 2 ms); `queue:failed` vacía | 3 s |
| `backup.sh run` | `.dump` de 252 KB validado con `pg_restore --list` | 0,4 s |
| Restauración A (base nueva) | `users`, `migrations` y `campuses` iguales a producción (1, 89, 5) | 1 s |
| Restauración B (sobre producción) | se alteró un dato, se restauró: volvió el original; `/up` 200 | 9 s |
| `basic_auth` del túnel | apagada: 200; encendida: 401 sin credenciales o con contraseña errónea, 200 con la correcta | — |

Desde el 10 de noviembre de 2026, `X-Content-Type-Options` y `Referrer-Policy` las pone la aplicación y no Caddy. Con la base casi vacía los tiempos de respaldo y restauración son mínimos; con los datos reales de R1 se medirán de nuevo en el servidor. Hallazgos corregidos en este ensayo: faltaba documentar el primer administrador (`ADMIN_INITIAL_PASSWORD` no estaba en la plantilla del `.env` y el seeder falla sin ella), el primer respaldo sale vacío por salir antes de las migraciones, y se añadió la contraseña opt-in para el túnel.

**Sigue esperando al servidor real:** el dominio y su DNS, los puertos 80 y 443 abiertos (el certificado de Let's Encrypt no se pudo ensayar, solo la CA interna), quién copia `BACKUP_DIR` fuera del servidor, y el primer PR `develop` → `main` con el tag `v1.0.0` (tarea 4.10).

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
9. ~~Correo saliente~~: resuelto el 22 de noviembre de 2026 con Microsoft 365 por Microsoft Graph y permisos delegados ([ADR-0032](../decisiones/0032-correo-por-microsoft-graph.md)); falta que TI registre la app de Azure y diga qué buzón será el remitente.
