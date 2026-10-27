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
| `nginx` | nginx 1.30.6 (stable) | Reverse proxy público (`:80`) |
| `app` | FrankenPHP 1.13.4 · PHP 8.5 + Laravel Octane | App en worker mode (`:8000` interno) |
| `postgres` | PostgreSQL 18.6 | Base de datos |
| `valkey` | Valkey 9.1.2 | Cache, sesiones y colas (driver `redis`) |
| `queue` | misma imagen que `app` | Worker de colas (`queue:work`; en dev `queue:listen` para recargar código) |
| `vite` *(solo dev)* | Node 24.22.1 LTS | Vite + HMR (`:5173`) |

Todas las imágenes están fijadas por versión exacta **y digest** (`@sha256:…`). Para actualizar una, cambia ambos: `docker buildx imagetools inspect <imagen>:<tag>`.

Sin pooler de conexiones: con Octane cada worker mantiene una conexión persistente a Postgres, así que el total es aprox. `workers × réplicas + workers de queue`. Si en el futuro se escala a varias réplicas/servidores o muchos workers, conviene añadir PgBouncer delante de `postgres`.

### Desarrollo

```bash
cp .env.example .env
docker compose up -d --build        # usa compose.yaml + compose.override.yaml
docker compose exec app composer install
docker compose exec app php artisan key:generate
docker compose exec app php artisan migrate
```

- App: http://localhost · Vite: http://localhost:5173
- El código se monta como volumen y Octane recarga los workers al detectar cambios (`--watch`).
- Postgres queda expuesto en `localhost:5432` para clientes de BD.

### Producción (imagen inmutable)

El `Dockerfile` es multi-stage: `base` → `vendor` (composer, cacheado por `composer.lock`) → `assets` (Vite, cacheado por `package-lock.json`) → `prod`. La imagen `prod` lleva código, `vendor` sin dependencias de dev y assets compilados; corre como usuario sin privilegios y cachea config/rutas/vistas al arrancar.

```bash
APP_IMAGE_TAG=$(git rev-parse --short HEAD) docker compose -f compose.yaml build
APP_IMAGE_TAG=$(git rev-parse --short HEAD) docker compose -f compose.yaml up -d
```

`RUN_MIGRATIONS=true` ejecuta `migrate --force` al arrancar el contenedor.

## Tests

```bash
docker compose exec app php artisan test                    # suite completa
docker compose exec app php artisan test --filter=Nombre    # una prueba
docker compose exec app ./vendor/bin/pint --dirty           # formato antes de cada commit
```

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
| `docs/` | Proceso, dominio, arquitectura, fases, herramientas y decisiones |
