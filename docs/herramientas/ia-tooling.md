# Herramientas de IA para trabajar en Nexus

Qué se usa, para qué, y qué no hace falta. Criterio: lo justo para que tres personas produzcan código probado y consistente con el plan.

## Ya instalado (y cómo se usa aquí)

| Herramienta | Tipo | Uso en Nexus |
|---|---|---|
| **superpowers** | Plugin de proceso | Obligatorio. `brainstorming` cuando un issue tiene ambigüedad; `writing-plans` para volver detalladas las fases 🟦; `test-driven-development` en toda implementación; `systematic-debugging` ante cualquier bug; `using-git-worktrees` al iniciar una rama; `requesting-code-review` y `verification-before-completion` antes de abrir PR. |
| **laravel-boost** | Plugin con servidor MCP | Documentación exacta de Laravel 13, Livewire 4, Pest 5 y paquetes instalados; tinker, esquema de BD, logs y errores del navegador. El servidor del plugin global falla al conectar porque busca PHP local; el del proyecto (`.mcp.json`) corre dentro del contenedor (ver abajo). |
| **playwright** | Plugin con servidor MCP | Exploración manual de la UI por la IA (abrir una pantalla, hacer clic, leer errores de consola) cuando un browser test falla y hay que entender por qué. Los E2E automatizados son de Pest, no de este plugin. |
| **caveman** | Plugin de estilo | Opcional. Reduce tokens en sesiones largas. No afecta el código. |

## Configuración del proyecto

### Laravel Boost dentro de Docker

Boost (`laravel/boost`, solo `require-dev`) está instalado en el contenedor y no hay PHP local. Lo que queda versionado:

| Archivo | Para qué |
|---|---|
| `.mcp.json` | Servidor `boost`: `docker compose exec -T app php artisan boost:mcp`. No lo reescribas con `boost:install --mcp`: generaría una entrada `laravel-boost` con rutas de PHP local. |
| `boost.json` | Qué instaló Boost (agente `claude_code`, guías y MCP; sin *skills*). `boost:update` lo lee. |
| `config/boost.php` | Configuración publicada. Fija `agents.claude_code.guidelines_path = AGENTS.md` para que las guías no se inyecten en `CLAUDE.md`. |
| `AGENTS.md` | Bloque `<laravel-boost-guidelines>` generado (Laravel 13, Livewire 4, Pest 5, Pint, Octane). `CLAUDE.md` lo importa con `@AGENTS.md`. No se edita a mano. |

Notas de uso:

- El `.mcp.json` ejecuta en `/app`, la carpeta principal montada en el contenedor, no en los worktrees. Para que el MCP funcione, esa carpeta debe estar en `develop` con `composer install` hecho y los contenedores arriba (`docker compose up -d`).
- Regenerar las guías cuando cambien las dependencias (`composer require`/`update`): `docker compose exec app php artisan boost:update`, y commitear el cambio de `AGENTS.md`. Para cambiar qué se instala (agentes, paquetes de terceros) se repite `boost:install`; revisa después que `CLAUDE.md` y `.mcp.json` sigan intactos (`git diff`).
- No se instalan las *skills* de Boost en `.claude/skills/`: el proyecto solo tendrá las tres propias de abajo. Por eso `boost.json` no lista `skills`.
- Comprobación rápida del servidor (JSON-RPC por stdin): `initialize`, `notifications/initialized` y `tools/list` a `docker compose exec -T app php artisan boost:mcp`. Herramientas actuales: `application-info`, `browser-logs`, `database-connections`, `database-query`, `database-schema`, `get-absolute-url`, `last-error`, `read-log-entries`, `record-rule`, `search-docs`.
- El servidor del plugin global (`plugin:laravel-boost`) falla con `Executable not found in $PATH: php` porque busca PHP local. No estorba; se puede desactivar en `/plugin` o en `~/.claude/settings.json` (`enabledPlugins`: `"laravel-boost@claude-plugins-official": false`) para quitar el error. El que se usa es `boost`, el del proyecto.

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
