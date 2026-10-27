# Herramientas de IA para trabajar en Nexus

Qué se usa, para qué, y qué no hace falta. Criterio: lo justo para que tres personas produzcan código probado y consistente con el plan.

## Ya instalado (y cómo se usa aquí)

| Herramienta | Tipo | Uso en Nexus |
|---|---|---|
| **superpowers** | Plugin de proceso | Obligatorio. `brainstorming` cuando un issue tiene ambigüedad; `writing-plans` para volver detalladas las fases 🟦; `test-driven-development` en toda implementación; `systematic-debugging` ante cualquier bug; `using-git-worktrees` al iniciar una rama; `requesting-code-review` y `verification-before-completion` antes de abrir PR. |
| **laravel-boost** | Plugin con servidor MCP | Documentación exacta de Laravel 13, Livewire 4, Pest 4 y paquetes instalados; tinker, esquema de BD, logs y errores del navegador. Hoy **falla al conectar** porque busca PHP local. Solución: instalar Boost en el contenedor y configurar el MCP del proyecto con `docker compose exec` (ver abajo). |
| **playwright** | Plugin con servidor MCP | Exploración manual de la UI por la IA (abrir una pantalla, hacer clic, leer errores de consola) cuando un browser test falla y hay que entender por qué. Los E2E automatizados son de Pest, no de este plugin. |
| **caveman** | Plugin de estilo | Opcional. Reduce tokens en sesiones largas. No afecta el código. |

## Por configurar en la fase 0

### Laravel Boost dentro de Docker

1. Instalar en el contenedor: `docker compose exec app composer require laravel/boost --dev` y `docker compose exec app php artisan boost:install` (regenera `AGENTS.md`, que `CLAUDE.md` importa).
2. El archivo `.mcp.json` del proyecto declara el servidor `boost` ejecutando `docker compose exec -T app php artisan boost:mcp`. Requiere que los contenedores estén arriba.
3. Desactivar el servidor del plugin global (`plugin:laravel-boost`) en este proyecto para no ver el error de conexión, o dejarlo; no estorba.

### Permisos y hooks de Claude Code

`.claude/settings.json` del proyecto permite sin preguntar los comandos de Docker, Pest, Pint y `gh` que se usan todo el día, y bloquea los peligrosos (push forzado, borrar volúmenes, leer `.env`). Un hook opcional corre Pint sobre cada archivo PHP editado; se activa cuando los contenedores están siempre arriba.

### Skills del proyecto (`.claude/skills/`)

Se crean cuando exista el código que describen, no antes, para que no se desvíen:

| Skill | Cuándo se crea | Qué enseña |
|---|---|---|
| `nexus-module` | Fase 0, tras el módulo de referencia | Crear un módulo nuevo: carpetas, provider, namespace Livewire, migraciones, factories, pruebas en tres niveles |
| `nexus-import-template` | Fase 1, tras la primera plantilla | Agregar una plantilla de importación: columnas, reglas, clave natural, aplicación, fixture y pruebas |
| `nexus-constraint-rule` | Fase 3, tras las primeras reglas | Agregar una regla HC o SC: clase, id, mensaje, prueba unitaria, registro en el validador y en el documento de reglas |

Tres skills bastan; cualquier otra convención va en `CLAUDE.md`.

## Flujo por issue

1. `gh issue view <n>`; leer la fase en `docs/plan/`.
2. Worktree y rama `feature/<n>-<slug>` desde `develop`.
3. Si hay ambigüedad: brainstorming (la IA pregunta con opciones); si no, TDD directo.
4. Rojo → verde → refactor dentro del contenedor; Boost para consultar la API exacta de Laravel, Livewire y Pest.
5. `/code-review` y, en fases con pantallas nuevas, `/security-review`.
6. PR con la plantilla; actualizar el archivo de la fase.

## Lo que no hace falta (por ahora)

| Idea | Por qué no |
|---|---|
| MCP de GitHub | `gh` ya cubre issues, PRs, etiquetas y proyectos con menos configuración. |
| MCP de PostgreSQL | Boost consulta el esquema y ejecuta consultas; para más, `docker compose exec postgres psql`. |
| Context7 u otro MCP de documentación general | Boost cubre el ecosistema Laravel. MaryUI y daisyUI tienen documentación corta que la IA ya conoce; si en la fase 3 hace falta, se agrega entonces. |
| Agentes personalizados | Los agentes por defecto y los de superpowers bastan para un equipo de tres. |
| Workflows multiagente | Útiles para revisiones grandes; se consideran en la retrospectiva de R1, no antes. |

## Reglas de uso de la IA en el equipo

- Una sesión por issue. Al cambiar de issue, nueva sesión (o `/clear`) para no arrastrar contexto.
- La IA escribe la prueba antes que la implementación y muestra el resultado de la suite; la persona revisa que la prueba pruebe la regla correcta.
- Datos reales del SII nunca se pegan en el chat. Los fixtures van anonimizados.
- Lo que la IA no encuentre en `docs/` se pregunta al equipo y se documenta; no se inventa.
