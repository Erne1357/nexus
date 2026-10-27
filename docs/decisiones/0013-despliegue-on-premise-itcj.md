# ADR-0013 — Despliegue on-premise en el ITCJ

**Estado:** Aceptada; hay puntos por confirmar con el ITCJ (ver el final) · **Fecha:** 2026-10-27

## Contexto
`PROJECT.md` dejaba como supuesto un servidor "por confirmar: on-premise del ITCJ o un VPS", y la tarea 0.14 pide cerrarlo antes del piloto (fase 4, tarea 4.7). La imagen `prod` ya existía (inmutable, con `optimize` y migraciones opcionales al arrancar), pero el stack base solo ofrecía nginx en el puerto 80 sin cifrado, sin respaldos y sin un lugar compartido para los archivos que escriben `app` y `queue`.

El 2026-10-27 se acordó:
- **Servidor:** on-premise del ITCJ, no un VPS.
- **Acceso:** por un dominio público del ITCJ (el nombre exacto aún no se conoce; va en una variable).
- **Red:** el servidor tiene salida a internet; baja las imágenes y construye ahí.
- **Respaldos:** `pg_dump` diario en un directorio local del servidor, con retención. Copiarlos fuera del servidor queda a cargo del ITCJ.

## Decisión
| Tema | Decisión |
|---|---|
| Orquestación | Docker Compose en un solo servidor. `compose.yaml` queda como base y `compose.prod.yaml` es un overlay: `docker compose -f compose.yaml -f compose.prod.yaml up -d`. No se usa `compose.override.yaml` (solo desarrollo). |
| HTTPS | **Caddy** como único punto de entrada público, con certificados de Let's Encrypt obtenidos y renovados por él mismo. Reemplaza a nginx en producción. |
| Versión desplegada | Se construye en el servidor desde un tag de git (`v1.0.0`) y la imagen se etiqueta con `APP_IMAGE_TAG`. Volver atrás es cambiar el tag. |
| Secretos | Un `.env` en el servidor con permisos `600`, fuera de git, a partir de `.env.production.example`. |
| Migraciones | Las corre el contenedor `app` al arrancar (`RUN_MIGRATIONS=true`); `queue` espera a que `app` esté sano. |
| Respaldos | Servicio `backup` con `pg_dump -Fc` diario hacia `BACKUP_DIR`: 14 diarios y 8 semanales. |
| Registros | Laravel escribe a `stderr`; Docker rota 5 archivos de 10 MB por servicio. |

### HTTPS: Caddy
- **Renovación sin piezas extra.** Caddy obtiene el certificado al arrancar y lo renueva solo; no hay cron, ni contenedor de certbot, ni recarga del proxy. Con nginx + certbot hay que coordinar dos procesos y el arranque inicial necesita un certificado ficticio para que nginx pueda levantar.
- **Un archivo corto.** `docker/caddy/Caddyfile` son unas 40 líneas: dominio, compresión, límite de cuerpo de 20 MB (igual que PHP), cabeceras de seguridad y el proxy a `app:8000`.
- **Se puede probar sin el dominio real.** El mismo Caddyfile acepta `CADDY_GLOBAL_OPTIONS`: `local_certs` usa una CA interna (pruebas locales) y `acme_ca <staging de Let's Encrypt>` sirve para ensayar en el servidor sin gastar el límite de certificados. En producción la variable va vacía.
- **Los despliegues no requieren recargarlo.** Caddy resuelve `app` en cada conexión nueva y espera hasta 15 s si el contenedor aún no responde; en la prueba local de actualización las peticiones durante la recreación de `app` respondieron 200.
- **Separado de la aplicación.** Si el certificado viviera dentro del contenedor `app`, cada despliegue reiniciaría también el TLS. Con Caddy aparte, `app` se recrea sin tocar el certificado, que persiste en el volumen `caddy-data`.
- **Costo:** una imagen nueva, la oficial `caddy:2.11.7-alpine`, fijada por tag y digest como las demás (`sha256:eb4d35b7…`).

### Cómo se compone el stack
- `compose.yaml` y `compose.override.yaml` no cambian de comportamiento: en desarrollo sigue habiendo nginx en el puerto 80, sin HTTPS.
- `compose.prod.yaml` pone el servicio `nginx` en el perfil `dev-only` (no arranca), agrega `caddy` y `backup`, y declara los volúmenes `app-storage`, `caddy-data` y `caddy-config`.
- Solo `caddy` publica puertos (80, 443 TCP y 443 UDP para HTTP/3). PostgreSQL, Valkey y `app` solo existen dentro de la red de Docker, así que el firewall del host no tiene que protegerlos.
- `app-storage` es un volumen compartido entre `app` y `queue` en `storage/app`. En desarrollo ambos montan la misma carpeta; en producción cada contenedor tiene su propio sistema de archivos y sin el volumen un job de importación no vería el archivo que subió la pantalla.
- `HTTP_PORT` y `HTTPS_PORT` existen para pruebas locales; en el servidor son 80 y 443.

### Secretos
- El `.env` del servidor contiene `APP_KEY`, `DB_PASSWORD`, `REDIS_PASSWORD`, `APP_DOMAIN` y `ACME_EMAIL`. Compose lo usa para interpolar y para inyectar variables a los contenedores. Se crea con `chmod 600` y nunca entra a git (`.gitignore` ya lo excluye; la imagen tampoco lo copia).
- `compose.prod.yaml` **fuerza** `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `LOG_CHANNEL=stderr` y `APP_URL=https://$APP_DOMAIN`. Así un `.env` copiado de desarrollo no deja la depuración activa en producción, y el dominio se define una sola vez.
- Las contraseñas se generan con `openssl rand -hex 24` (sin `$` ni espacios, que Compose interpretaría).
- `POSTGRES_PASSWORD` solo se aplica al crear el volumen: cambiar `DB_PASSWORD` después exige `ALTER USER` (el runbook lo explica).

### Migraciones al desplegar
- El `entrypoint` ya ejecutaba `php artisan migrate --force` si `RUN_MIGRATIONS=true`. Como `app` y `queue` usan la misma imagen y el mismo `.env`, el overlay lo activa solo en `app` y lo desactiva en `queue`, que además espera a que `app` esté sano. Así hay un único proceso migrando y `queue` nunca ejecuta código nuevo sobre un esquema viejo.
- El healthcheck de `app` tiene `start_period` de 120 s (`APP_START_PERIOD`) porque las migraciones corren antes de que el servidor responda.
- Si una migración falla, `app` no llega a estar sano, `caddy` y `queue` no arrancan y el contenedor se reinicia en bucle; el runbook indica qué revisar. Las migraciones no se revierten solas: si fue destructiva, se restaura el respaldo hecho antes de actualizar.

### Respaldos
- Servicio `backup` con la misma imagen de PostgreSQL que `postgres` (mismo `pg_dump` que el servidor, sin imágenes de terceros) y un script propio, `docker/backup/backup.sh`.
- Un respaldo al día a las 03:00 hora de Ciudad Juárez (`BACKUP_TIME`), formato custom (`-Fc`, comprimido). Cada archivo se escribe como parcial, se valida con `pg_restore --list` y solo entonces toma su nombre final; un respaldo que falla nunca reemplaza a uno bueno.
- Retención: 14 diarios y 8 semanales (una copia por semana ISO, los domingos), configurables (`BACKUP_KEEP_DAILY`, `BACKUP_KEEP_WEEKLY`).
- Si no hay respaldo reciente al arrancar (primer despliegue o servidor apagado a la hora programada), respalda de inmediato. El healthcheck del servicio pasa a `unhealthy` si el último respaldo diario tiene más de 26 horas.
- Los archivos se crean con permisos `600` en un directorio `700` (`BACKUP_DIR`, por defecto `/var/backups/nexus`): contienen datos personales de alumnos y docentes.
- La restauración se documenta y se probó (ver el runbook).

## Consecuencias
- Un solo servidor significa sin alta disponibilidad. Una actualización deja la aplicación indisponible solo mientras `app` se recrea y migra (unos segundos en la prueba local; más si hay migraciones largas).
- Los respaldos viven en el mismo servidor, así que **no protegen contra la pérdida del servidor o del disco** hasta que el ITCJ los copie fuera. Es un riesgo aceptado temporalmente; debe tener responsable y fecha antes del piloto.
- El punto de recuperación es de hasta 24 horas (un `pg_dump` diario). Para la carga académica de R1 se considera suficiente; si el piloto exige menos, se evalúa archivado de WAL.
- Let's Encrypt limita los certificados repetidos para el mismo nombre (5 por semana). Hay que conservar el volumen `caddy-data` y no usar `docker compose down -v` en producción.
- Let's Encrypt necesita llegar al servidor por los puertos 80 y 443 desde internet y un DNS público que apunte a él. Si el ITCJ no puede abrirlos, el plan B es un certificado entregado por el ITCJ (una línea `tls cert.pem key.pem` en el Caddyfile y un volumen de solo lectura con los archivos) o un desafío DNS-01, que exige una imagen de Caddy compilada con el plugin del proveedor de DNS.
- El Caddyfile registra los accesos en la salida estándar de `caddy`; se conservan solo los últimos 50 MB por la rotación de Docker.
- `docker/nginx/default.conf` queda como configuración exclusiva de desarrollo.
- Una guardia automática (`tests/Unit/Deploy/ProductionStackTest.php`) comprueba que las imágenes sigan fijadas por digest, que solo `caddy` publique puertos, que los valores seguros se fuercen, que las migraciones corran solo en `app` y que la plantilla de `.env` no lleve secretos.
- No cambia el modelo de datos ni se agrega ninguna dependencia de PHP o npm. La única imagen nueva es `caddy`.

## Alternativas descartadas
- **nginx + certbot (contenedor de renovación):** funciona, pero son dos procesos coordinados, un certificado ficticio para el primer arranque y una recarga de nginx tras cada renovación. Más piezas que fallan en silencio por un servicio que debe ser aburrido.
- **FrankenPHP/Octane sirviendo HTTPS directo:** quita un contenedor, pero ata la gestión del certificado al ciclo de vida de `app` (cada despliegue lo reinicia), obliga a reemplazar el Caddyfile que genera Octane y deja a `queue`, que comparte la imagen, con configuración de TLS que no usa.
- **Caddy también en desarrollo (sustituir nginx en `compose.yaml`):** cambiaría el entorno local, donde un certificado interno estorba más de lo que ayuda.
- **Docker secrets, Swarm o un gestor de secretos:** Laravel lee variables de entorno, no archivos; habría que adaptar el arranque (`*_FILE`) para proteger valores que Docker de todos modos expone a quien tenga acceso al socket. Un `.env` con permisos `600` es suficiente para un solo servidor.
- **`cron` del host con `docker exec pg_dump`:** depende de configurar el servidor a mano y no viaja con el repositorio. El servicio `backup` se despliega, se prueba y se reinicia junto con el resto.
- **Imágenes de terceros para respaldos:** una dependencia más que vigilar para algo que cabe en unas 140 líneas de shell sobre la imagen que ya usamos.
- **PgBackRest o archivado de WAL (recuperación a un punto en el tiempo):** el volumen de datos y el ritmo de cambios en R1 no justifican la operación adicional. Se reconsidera si el piloto lo pide.
- **VPS:** descartado por el ITCJ al elegir servidor propio.

## Por confirmar con el ITCJ
Nada de esto bloquea el trabajo de desarrollo, pero todo debe estar resuelto antes del despliegue del piloto (tarea 4.7) y lo primero para desplegar `develop` en el servidor de pruebas (criterio de la tarea 0.14).

| # | Pendiente | Por qué importa |
|---|---|---|
| 1 | **Nombre del dominio** (por ejemplo, un subdominio de `itcj.edu.mx`) | Es `APP_DOMAIN`; el certificado se emite para ese nombre. |
| 2 | **DNS:** quién crea el registro A (y AAAA si hay IPv6) hacia la IP pública que llega al servidor | Let's Encrypt valida que el dominio apunte al servidor. |
| 3 | **Puertos 80 y 443** (TCP; 443/UDP opcional para HTTP/3) abiertos desde internet hacia el servidor, con la traducción de red que haga falta | Sin ellos no se emite ni se renueva el certificado. El 80 también redirige a HTTPS. |
| 4 | **Características del servidor:** CPU, RAM, disco, sistema operativo y versión, IP fija, reloj sincronizado (NTP) | Define los workers de Octane, el ajuste de PostgreSQL y el espacio para imágenes, volúmenes y 22 respaldos. Los certificados fallan con el reloj desfasado. |
| 5 | **Docker Engine y el plugin Compose** instalados, o permiso para instalarlos | El runbook los da por hechos. Probado con Docker 29 y Compose 5. |
| 6 | **Quién tiene acceso** (SSH, `sudo`, grupo `docker`), desde qué red (¿VPN?) y quién es el responsable en el ITCJ | El grupo `docker` equivale a administrador del servidor. Debe haber una lista corta y nombrada. |
| 7 | **Salida a internet** hacia Docker Hub, GitHub, Packagist, npm y `acme-v02.api.letsencrypt.org`; si hay proxy institucional, cuál | La construcción de la imagen y la emisión del certificado dependen de ello. |
| 8 | **Copia de los respaldos fuera del servidor:** quién, con qué herramienta y con qué frecuencia copia `BACKUP_DIR` a otro equipo o almacenamiento | Sin copia externa, perder el disco es perder los datos. Los archivos contienen datos personales. |
| 9 | **Servidor de pruebas:** si es otra máquina distinta de producción, o el mismo servidor con otro dominio | La tarea 0.14 se cierra con el despliegue de `develop` ahí. |
| 10 | **Correo saliente** (SMTP institucional), si algún flujo lo requiere | R1 no envía correos; hoy `MAIL_MAILER=log`. |
