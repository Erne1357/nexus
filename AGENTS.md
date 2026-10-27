# AGENTS.md

Guías generadas por Laravel Boost para este proyecto. Hasta que se instale Boost dentro del contenedor (tarea 0.13 de `docs/plan/fase-0-fundacion.md`), este archivo solo contiene esta nota.

Instalación (siempre dentro del contenedor, nunca con PHP local):

```bash
docker compose exec app composer require laravel/boost --dev
docker compose exec app php artisan boost:install
```

`boost:install` reemplaza el contenido de este archivo con las guías de Laravel 13, Livewire 4, Pest 4 y los paquetes instalados. Las reglas específicas de Nexus siguen en [CLAUDE.md](CLAUDE.md), que importa este archivo.
