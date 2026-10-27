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
| `pgbouncer` | PgBouncer 1.26.1 | Pool de conexiones (modo `transaction`) |
| `postgres` | PostgreSQL 18.6 | Base de datos |
| `valkey` | Valkey 9.1.2 | Cache, sesiones y colas (driver `redis`) |
| `queue` | misma imagen que `app` | Worker de colas (`queue:work`; en dev `queue:listen` para recargar código) |
| `vite` *(solo dev)* | Node 24.22.1 LTS | Vite + HMR (`:5173`) |

Todas las imágenes están fijadas por versión exacta **y digest** (`@sha256:…`). Para actualizar una, cambia ambos: `docker buildx imagetools inspect <imagen>:<tag>`.

La app se conecta a Postgres **siempre a través de PgBouncer** (`DB_HOST=pgbouncer`).

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
docker compose exec app php artisan test
```
