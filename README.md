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

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install && npm run dev
php artisan serve
```

## Tests

```bash
php artisan test
```
