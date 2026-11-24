# ADR-0033 — Despliegue continuo con paquetes de release y blue/green

**Estado:** Aceptada (decisiones del usuario, 23 de noviembre de 2026) · **Fecha:** 2026-11-23 · Tarea 1.31 (#785 a #790) · Rama padre `riel/despliegue` · Reemplaza las filas «Versión desplegada» y «Migraciones» del [ADR-0013](0013-despliegue-on-premise-itcj.md)

## Contexto
Con el ADR-0013 el despliegue era a mano: en el servidor se hacía `git checkout` de un tag, se construía la imagen ahí mismo, y el contenedor `app` corría las migraciones al arrancar. Mientras `app` se recreaba y migraba, el sitio no respondía. Volver atrás era cambiar el tag sin tocar el esquema, y `/up` solo decía que el proceso estaba vivo.

El usuario pidió el 23 de noviembre de 2026 un despliegue continuo hacia `main` con estas características:
- imágenes inmutables;
- rollbacks verdaderos;
- un `/health` que revise la caché, la base y los conflictos de migraciones;
- despliegues sin caídas, para poder desplegar seguido;
- revisar si el CI necesita más shards.

Decidió por AskUserQuestion:

| # | Pregunta | Respuesta |
|---|---|---|
| D1 | Cómo llega el despliegue al servidor | El servidor jala; nada entra al servidor desde GitHub |
| D2 | Disparo | Automático en cada merge a `main`, si pasa el smoke del stack de producción |
| D3 | Migraciones que borran o renombran | Expand/contract: el CI rechaza las destructivas sin una marca; todo despliegue es sin caída; volver atrás de un release marcado exige restaurar el respaldo |
| D4 | Staging | Solo producción; el CI levanta el stack de producción completo con la imagen real |
| D5 | El servidor hoy | Ya existe, pero **sin salida a internet**: hace falta un paquete offline que se copie al servidor |
| D6 | Horario | Cualquier hora, con interruptor de pausa en GitHub y en el servidor |

## Decisión

### Una sola construcción, un paquete por release
- `deploy.yml` construye una vez, en cada push a `main`, tres imágenes:
  - la app (etapa `prod`, con `NEXUS_RELEASE=<sha>`, que `/health` reporta);
  - el motor;
  - el deployer.

  Las publica en GHCR con el tag `sha-<sha>`.
- Después corre el smoke con esos digests, sin reconstruir.
- Al final arma el **paquete del release** (`nexus-<sha>.tar` y su `.sha256`) y lo deja como artefacto por 30 días. Contiene:
  - las imágenes, propias y de terceros;
  - la infraestructura de ese SHA (`compose.yaml`, `compose.prod.yaml`, `docker/`, `.env.production.example`);
  - un `manifest.json`.
- El servidor ya no construye ni necesita `git`.
- Las imágenes de terceros viajan con una etiqueta local derivada de su digest (`nexus-local/<nombre>:<digest corto>`), porque `docker load` no conserva el digest del registro en todos los almacenes de imágenes.
- La integridad la da el `sha256` del paquete, que se publica en el mismo workflow. El deployer no despliega un paquete que no coincida.
- Sin internet (hoy), desplegar es descargar el artefacto en una máquina con acceso y copiarlo a la carpeta de entrada del servidor (`/opt/nexus/inbox`).
- Con internet (`DEPLOY_SOURCE=github`):
  - el job `release` crea un **Deployment de GitHub** en el ambiente `production`, con los digests en el payload;
  - el deployer lo consulta cada minuto con un token *fine-grained* que solo tiene Deployments (en `deployer.env`, que no lee la app), descarga por digest (solo imágenes `ghcr.io/erne1357/nexus-*`) y publica `in_progress` y luego `success` o `failure`;
  - el workflow espera ese resultado y falla si el despliegue falló, así que GitHub avisa por correo;
  - si nadie lo toma en 30 minutos, solo deja un aviso.

### Cero caídas: blue/green detrás de Caddy
- `app` se parte en `app-blue` y `app-green`, idénticos salvo el nombre (una prueba lo exige).
- Caddy los tiene a los dos como upstream con `lb_policy first` y health checks activos a `/health` cada 2 s. Manda todo al primer color sano y nunca reparte entre versiones.
- El deployer:
  1. arranca el color inactivo con la versión nueva, drenado: un archivo hace que su `/health` le responda 503 a la sonda de Caddy (cabecera `X-Health-Probe: lb`), que no le manda tráfico;
  2. espera a que su `/health` responda 200 con el release esperado;
  3. recrea `queue` y `scheduler` con la imagen nueva;
  4. pone el color nuevo en la rotación y drena el viejo; Caddy cambia todo el tráfico en un intervalo;
  5. detiene el viejo con apagado ordenado. El contenedor queda **detenido, no borrado**: volver al release anterior es encenderlo.

  Con `lb_policy first`, sin el arranque drenado Caddy le mandaría tráfico a `app-blue` en cuanto respondiera sano, antes de actualizar los workers.
- Si una conexión falla justo al detener un color, Caddy reintenta en el otro (`lb_try_duration`).
- **Assets:** una página que sirvió la versión vieja pide `/build/assets/<hash viejo>`. Caddy sirve `/build/*` desde un volumen (`build-assets`) donde el deployer deja los assets de los últimos releases. Las peticiones de Livewire de una página vieja sí pueden llegar al código nuevo: se acepta (Livewire pide recargar si el componente cambió).
- Caddy y los respaldos montan su configuración desde una carpeta estable (`NEXUS_CONFIG_DIR`). Así Compose no los recrea en cada release. Un Caddyfile nuevo se aplica con `caddy reload`, sin cortar conexiones.
- El motor solo se recrea si cambió su imagen, y cuando no tiene corridas en curso (`runs_in_progress = 0`).

### Migraciones expand/contract
- Las corre el servicio `migrate`, de un solo uso, con la imagen nueva y antes de arrancar su color, con `--isolated`. Mientras tanto la versión vieja sigue atendiendo, así que toda migración tiene que funcionar con el código del release anterior:
  - columnas nuevas `nullable()` o con `default()`;
  - borrar, renombrar o cambiar de tipo va en un release posterior al que dejó de usarlo, con `#[Contract('…')]` (`Core\Database\Contract`).
- `tests/Arch/MigrationSafetyTest.php` lo revisa en toda migración nueva (las que existían al crear el riel quedan en una línea base que no crece). La regla está en CLAUDE.md §5.
- `PGOPTIONS=-c lock_timeout=10s` (`MIGRATE_LOCK_TIMEOUT`): una migración que no consigue su candado falla, y el despliegue se aborta sin bloquear a la versión vieja.
- Antes de migrar, si la base ya tiene esquema, `backup.sh predeploy <sha>` deja un respaldo en `BACKUP_DIR/predeploy/`. Ese respaldo no entra en la rotación diaria.
- Los jobs en cola también son expand/contract: el código nuevo acepta los payloads del release anterior. Los workers se recrean antes de cambiar el tráfico, para que solo pase «job viejo → worker nuevo».

### Rollback verdadero
- **Sin contracciones de por medio:** volver a un release anterior es el mismo flujo blue/green con sus imágenes exactas (siguen en el servidor: `DEPLOY_KEEP_RELEASES=5`) y sin migrar. El esquema nuevo es compatible con el código viejo; `/health` lo marca `degraded` porque la base tiene migraciones que el código no conoce, pero atiende.
- **Con una contracción de por medio:** el deployer se niega (código 7). Con `--restore --yes`:
  1. detiene la app (Caddy responde la página de mantenimiento);
  2. restaura el respaldo previo a la primera contracción;
  3. vacía Valkey;
  4. despliega el destino.

  Se pierde lo escrito después de ese respaldo, y el mensaje lo dice. La restauración se hace en una base aparte que solo reemplaza a la real si salió bien; la de antes queda renombrada.
- La guardia cuenta toda migración con contracciones que llegó a correr: el deployer la registra antes y después de migrar, así que vale aunque el despliegue fallara después, y también para `deploy --sha` de un release anterior.
- No se usa `migrate:rollback`: las `down()` de las migraciones con datos no son confiables sobre una base de 4.4 GB.

### `/health` profundo (Core)
- `GET /health` va fuera del grupo `web`: sin sesión, sin cookies y sin throttle, porque esos dependen de Valkey y darían un 500 en lugar de un 503. `/up` sigue siendo la liveness de Docker.
- Chequeos **críticos** (si fallan, 503):
  - base de datos;
  - caché (escribe, lee y borra);
  - conexión de colas;
  - almacenamiento;
  - migraciones: pendientes o con nombre repetido → `fail`; desconocidas → `degraded`.
- Chequeos **no críticos** (si fallan, `degraded` con 200):
  - el motor;
  - los latidos del worker y del scheduler (cada minuto).
- Sin `X-Health-Token` (`HEALTH_TOKEN`, comparado con `hash_equals`) solo salen los estados, nunca mensajes, nombres de migraciones ni tamaños de colas. No se decide por la IP, que con `trustProxies('*')` se puede falsificar.
- El comando `nexus:migration-plan --json` usa la misma lógica; el deployer lo corre con la imagen nueva antes de migrar.

### El deployer
- Un servicio de Compose propio (`docker/deployer`) sobre `docker:29.10.0-cli` fijado por digest, con `bash`, `curl`, `jq`, `zstd` y `util-linux`.
- Monta el socket de Docker y `NEXUS_HOME` en la **misma ruta** que en el servidor: Compose resuelve ahí las rutas relativas y el daemon las lee del host.
- Comandos: `deploy`, `rollback`, `status`, `pause`/`resume`, `compose` (Compose del release activo) y `agent`, el bucle del servicio.
- El agente revisa cada `DEPLOY_POLL_SECONDS` la carpeta de entrada (o GitHub). Solo toma un paquete con su `.sha256` y cuyo tamaño ya no cambia, y despliega el más nuevo. Archiva cada pedido en `hechos/`, `fallidos/` u `omitidos/` con su registro.
- Un despliegue a la vez (`flock`).
- Historial en `.deploy/releases.log`.
- Códigos de salida documentados en el runbook.
- Se actualiza a sí mismo desde otro contenedor: si se recreara él mismo, moriría a medias.

### CI
- El job `prod-smoke` (`.github/scripts/prod-smoke.sh`) corre en los PR hacia `main` y en los que tocan la infraestructura del despliegue. Levanta el stack de producción con el paquete y recorre, con tráfico continuo por Caddy (`/login`, un asset y `/health` cada ~100 ms):
  1. primer arranque;
  2. despliegue;
  3. versión rota;
  4. `.env` incompleto;
  5. paquete alterado;
  6. rollback;
  7. contracción (negada y con restauración);
  8. reinicio del daemon de Docker;
  9. el agente con la carpeta de entrada;
  10. el agente con GitHub (contra una API falsa), con pausa.

  Falla con una sola respuesta 5xx o sin conexión. También pasa `shellcheck` y `actionlint`.
- Los shards de `tests` se rebalancearon y Pint corre en paralelo (#791).

## Consecuencias
- Los despliegues ya no cortan el servicio, ni con migraciones. El costo es disciplina:
  - toda migración y todo payload de job debe ser compatible con el release anterior;
  - las contracciones se separan en un release posterior.
- Durante un cambio de color corren dos instancias de la app (unos segundos). `OCTANE_WORKERS` ajusta los workers por color si la RAM del servidor es corta.
- El respaldo previo de una base de 4.4 GB tarda minutos. Solo se toma cuando el release trae migraciones, y la versión vieja sigue atendiendo mientras tanto.
- Actualizar PostgreSQL, Valkey o Caddy (otra imagen de terceros) sí los reinicia: es poco frecuente y se anota en el PR que cambie esas imágenes.
- Sin internet, Let's Encrypt no puede emitir el certificado: antes del arranque real hace falta el certificado del ITCJ (plan B del ADR-0013). El correo por Microsoft Graph (ADR-0032) también necesita salida.
- El deployer administra el servidor por el socket de Docker, igual que el grupo `docker` (ADR-0013). Su token de GitHub, cuando exista, solo puede tocar Deployments.
- Los paquetes de GHCR serán públicos: solo traen lo que ya es público (código del repo y `vendor`), nunca `.env`.
- Dependencias nuevas, solo de infraestructura:
  - la imagen `docker:29.10.0-cli` (base del deployer);
  - en el CI, `koalaman/shellcheck:v0.11.0` y `rhysd/actionlint:1.7.12`.

  Las tres van fijadas por digest. Ninguna dependencia de PHP ni de npm.

## Alternativas descartadas
- **SSH desde GitHub Actions:** exige abrir el puerto 22 o una VPN, y el servidor no tiene salida.
- **Runner self-hosted en el servidor:** GitHub lo desaconseja en repos públicos (un PR de un fork podría ejecutar código ahí), y sin internet no funciona.
- **Construir en el servidor desde un tag:** dos construcciones del mismo commit pueden diferir, y necesita Packagist, npm y Docker Hub.
- **Rolling por escala (`--scale app=2`):** hay que rastrear contenedores por id, reparte tráfico entre versiones y el rollback siempre recrea.
- **Modo mantenimiento en cada despliegue:** no es zero downtime.
- **`migrate:rollback` para volver atrás:** depende de que cada `down()` sea correcta, incluidas las migraciones con datos.
- **Swarm o Kubernetes:** un solo servidor (ADR-0013).
- **Firmar el paquete (cosign):** el `sha256` publicado en el mismo workflow basta mientras el paquete viaja dentro del ITCJ. Se reconsidera si se distribuye por otros canales.
