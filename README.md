# Nexus

Aplicación construida con **Laravel 13** y **Livewire 4**.

## Convenciones

- Los componentes Livewire usan el formato **multi-file (MFC)**: cada componente vive en su propia carpeta con `nombre.php` (clase anónima) y `nombre.blade.php` (vista).
  - `resources/views/components/` → componentes reutilizables (`<livewire:counter />`)
  - `resources/views/pages/` → componentes de página (`Route::livewire('/', 'pages::home')`)
  - `resources/views/layouts/` → layouts (`layouts::app` por defecto)
- `php artisan make:livewire nombre` genera MFC por defecto (ver `config/livewire.php` → `make_command`).

## Ramas

- `main` → producción / estable
- `develop` → integración; las features salen de aquí (`feature/*`) y vuelven por PR

## Stack (Docker)

| Servicio | Imagen | Rol |
|---|---|---|
| `nginx` *(solo dev)* | nginx 1.30.6 (stable) | Reverse proxy en desarrollo (`:80`, sin HTTPS) |
| `caddy` *(solo prod)* | Caddy 2.11.7 | Reverse proxy público con HTTPS automático (`:80` y `:443`); reemplaza a nginx en producción |
| `app` | FrankenPHP 1.13.4 · PHP 8.5 + Laravel Octane | App en worker mode (`:8000` interno) |
| `postgres` | PostgreSQL 18.6 | Base de datos |
| `valkey` | Valkey 9.1.2 | Cache, sesiones y colas (driver `redis`) |
| `queue` | misma imagen que `app` | Worker de colas (`queue:work`; en dev `queue:listen` para recargar código) |
| `backup` *(solo prod)* | PostgreSQL 18.6 (misma imagen que `postgres`) | `pg_dump` diario con retención hacia un directorio del servidor |
| `vite` *(solo dev)* | Node 24.22.1 LTS | Vite + HMR (`:5173`) |

Todas las imágenes están fijadas por versión exacta **y digest** (`@sha256:…`). Para actualizar una, cambia ambos: `docker buildx imagetools inspect <imagen>:<tag>`.

Sin pooler de conexiones: con Octane cada worker mantiene una conexión persistente a Postgres, así que el total es aprox. `workers × réplicas + workers de queue`. Si en el futuro se escala a varias réplicas/servidores o muchos workers, conviene añadir PgBouncer delante de `postgres`.

### Desarrollo

```bash
cp .env.example .env
docker compose build                                        # imagen dev (PHP, Node y Chromium)
docker compose run --rm --no-deps app composer install      # vendor antes de levantar: sin él, Octane no arranca
docker compose run --rm --no-deps app php artisan key:generate
docker compose up -d                                        # usa compose.yaml + compose.override.yaml
docker compose exec app php artisan migrate --seed          # roles, campus y usuario admin
```

- El seeder crea el usuario `admin` con `must_change_password`. Su contraseña inicial sale de `ADMIN_INITIAL_PASSWORD` en `.env`; en local, si no la defines, se genera una y se imprime en la consola. El primer acceso pide cambiarla.
- El contenedor `vite` instala `node_modules` al arrancar (incluye Playwright para los browser tests).

- App: http://localhost · Vite: http://localhost:5173
- El código se monta como volumen y Octane recarga los workers al detectar cambios (`--watch`).
- Postgres queda expuesto en `localhost:5432` para clientes de BD.

### Producción (imagen inmutable)

El `Dockerfile` es multi-stage: `base` → `vendor` (composer, cacheado por `composer.lock`) → `assets` (Vite, cacheado por `package-lock.json`) → `prod`. La imagen `prod` lleva código, `vendor` sin dependencias de dev y assets compilados; corre como usuario sin privilegios y cachea config/rutas/vistas al arrancar.

Producción es `compose.yaml` más el overlay `compose.prod.yaml` (nunca `compose.override.yaml`), con un `.env` propio a partir de `.env.production.example`:

```bash
docker compose -f compose.yaml -f compose.prod.yaml build app
docker compose -f compose.yaml -f compose.prod.yaml up -d
```

El overlay agrega HTTPS automático con Let's Encrypt (Caddy), respaldos diarios de PostgreSQL, un volumen compartido para `storage/app`, rotación de logs y valores seguros forzados (`APP_DEBUG=false`, cookies seguras). El contenedor `app` ejecuta `migrate --force` al arrancar (`RUN_MIGRATIONS=true`); `queue` no. Decisión en [ADR-0013](docs/decisiones/0013-despliegue-on-premise-itcj.md); primer despliegue, actualización, respaldo y restauración en [docs/operacion/despliegue.md](docs/operacion/despliegue.md).

## Tests

```bash
docker compose exec app php artisan test                       # suite completa
docker compose exec app php artisan test --filter=Nombre       # una prueba
docker compose exec app php artisan test --testsuite=Browser   # solo browser tests (Chromium)
docker compose exec app ./vendor/bin/pint                      # formato antes de cada commit (sin --dirty: no hay git en el contenedor)
```

### CI

Cada PR a `develop` y `main` corre en GitHub Actions tres checks: `lint` (Pint), `tests` (unit, feature y arquitectura, con reporte de cobertura) y `browser` (Chromium). Usan la misma imagen `dev` y los mismos servicios de compose que el desarrollo local. Detalle y tiempos en [docs/arquitectura/testing.md](docs/arquitectura/testing.md#ci).

## Laravel Boost (herramientas de IA)

Boost da a la IA documentación exacta de Laravel, Livewire y Pest, y acceso a esquema de BD, logs y tinker. Corre dentro del contenedor; `.mcp.json` ya apunta a él.

```bash
docker compose exec app php artisan boost:update    # regenerar AGENTS.md tras cambiar dependencias
docker compose exec app php artisan boost:mcp       # servidor MCP (lo lanza Claude Code, no se usa a mano)
```

Detalle y precauciones en [docs/herramientas/ia-tooling.md](docs/herramientas/ia-tooling.md).

## Documentación

| Archivo | Para qué |
|---|---|
| [CLAUDE.md](CLAUDE.md) | Guía operativa: cómo se trabaja en este repositorio (humanos e IA) |
| [PROJECT.md](PROJECT.md) | Qué es Nexus, alcance de cada entrega, decisiones, supuestos y riesgos |
| [PLAN.md](PLAN.md) | Roadmap y estado de cada fase |
| [CONTRIBUTING.md](CONTRIBUTING.md) | Ramas, commits, PRs y definición de hecho |
| [DESIGN.md](DESIGN.md) | Sistema de diseño (borrador) |
| [docs/operacion/despliegue.md](docs/operacion/despliegue.md) | Runbook de producción: despliegue, actualización, respaldos, restauración y fallas |
| `docs/` | Proceso, dominio, arquitectura, fases, herramientas y decisiones |
