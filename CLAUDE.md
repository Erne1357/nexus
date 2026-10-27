# CLAUDE.md — Guía operativa de Nexus

> Lee este archivo completo antes de proponer o cambiar código. Si una decisión choca con lo que dice aquí o en los documentos que enlaza, **detente y pregunta**; no asumas.

## 1. Qué es esto

Nexus es la nueva fuente de verdad académica del Instituto Tecnológico de Ciudad Juárez y reemplaza por etapas al SII. La primera entrega (R1) construye y publica la **carga académica** de Campus II para Ene-Jun 2027; después vienen el motor de horarios (R2) y la inscripción (R3).

- Contexto, alcance, decisiones y riesgos: [PROJECT.md](PROJECT.md)
- Estado de fases y módulos: [PLAN.md](PLAN.md) (la fase activa está en su encabezado)
- Proceso: `docs/proceso/`. Dominio: `docs/dominio/`. Arquitectura: `docs/arquitectura/`. Fases: `docs/plan/`.

## 2. Cómo arrancar una sesión

1. Lee [PLAN.md](PLAN.md) y el archivo de la fase activa en `docs/plan/`.
2. Toma un issue (`gh issue view <n>`). Cada issue referencia una tarea del plan (por ejemplo `3.2`).
3. Si la fase está marcada 🟦 (vaga), **no implementes**: primero una sesión de planeación que la vuelva detallada.
4. Trabaja en una rama `feature/<issue>-<slug>` creada desde `develop`, en un worktree propio dentro del repo (`.worktrees/<rama>`; comandos con `docker compose exec -w /app/.worktrees/<rama> app ...`, detalle en [CONTRIBUTING.md](CONTRIBUTING.md)).
5. Si el issue es claro, ve directo a TDD. Si hay ambigüedad, usa brainstorming y pregunta antes.
6. Antes de abrir el PR: suite completa verde, Pint, `/code-review`, documentación de la fase actualizada.

## 3. Stack y comandos

Todo corre en Docker. **No hay PHP, Composer ni Node en la máquina local**; nunca intentes instalarlos.

```bash
docker compose up -d --build                      # levantar (dev usa compose.override.yaml)
docker compose exec app php artisan test          # Pest: unit + feature + browser (tests/Browser)
docker compose exec app php artisan test --filter=NombreDelTest
docker compose exec app ./vendor/bin/pint         # formato antes de cada commit (sin --dirty: no hay git en el contenedor)
docker compose exec app php artisan migrate
docker compose exec app php artisan make:livewire <ruta>   # genera MFC por defecto
docker compose logs -f app queue
docker compose exec app composer require <paquete>         # dependencias siempre dentro del contenedor
```

| Pieza | Elección |
|---|---|
| Laravel 13 + Octane (FrankenPHP, PHP 8.5) | Worker mode: nada con estado mutable como singleton; usa `scoped` |
| Livewire 4 MFC + MaryUI (daisyUI) + Tailwind 4 | Sin SPA, sin API para la UI |
| PostgreSQL 18 | Única fuente de verdad; pruebas contra `nexus_test`, nunca SQLite |
| Valkey | Caché, sesiones y colas (`redis`) |
| Fortify (username) + spatie/permission + alcances propios | Sin registro público |
| spatie/activitylog | Bitácora inmutable |
| Pest 5 | Unit, feature, browser y arch |
| Laravel Boost (MCP) | Docs exactas de Laravel, Livewire y Pest; ver `docs/herramientas/ia-tooling.md` |

## 4. Arquitectura en diez líneas

Monolito modular: `app/Modules/<Modulo>/{Models,Enums,Actions,Queries,Rules,Livewire/{pages,components},Policies,Jobs,Routes,Database/{Migrations,Factories,Seeders},Lang}`. **No** es hexagonal ni DDD: sin capas Domain/Infrastructure, sin repositorios.

1. Las **escrituras** pasan solo por `Actions` (transacción, bitácora, evento).
2. Las **lecturas** de pantallas y exportes salen de `Queries`.
3. Los `Models` solo tienen relaciones, casts, scopes y accessors.
4. Los componentes Livewire validan, llaman Actions o Queries y renderizan. Nada de consultas en Blade.
5. Un módulo solo depende de los que están a su izquierda en el mapa de `docs/dominio/modelo-de-datos.md`; hacia la derecha se comunica con eventos.
6. Toda Policy usa `ScopedContext` (Core) para rol **y** alcance (campus, departamento, carrera).
7. Lo que tarda más de un segundo va a un job en cola.
8. El perfil de flujo por campus (`campuses.settings`) decide qué aplica; nunca `if ($campus->code === 'C2')`.
9. Las reglas de negocio tienen id (`HC-07`, `SC-01`) y viven en `docs/dominio/reglas-de-negocio.md`; el código las cita.
10. El módulo de referencia es `app/Modules/Core` (CRUD de Campus). Copia su forma.

Detalle: `docs/arquitectura/arquitectura.md`.

## 5. Convenciones

- **Idioma:** código, tablas, rutas, nombres de pruebas de arquitectura y commits en inglés; interfaz, documentación, issues y descripciones de pruebas en español. Términos del dominio según `docs/dominio/glosario.md`.
- **Nombres:** Actions = verbo + sustantivo (`AssignTeacher`, `CloseLoad`); Queries = sustantivo + `Query`; reglas = `Rules/Constraints/<Nombre>` con el id de la regla en el docblock.
- **Livewire MFC:** una carpeta por componente con `<nombre>.php` (clase anónima) y `<nombre>.blade.php`; namespace `<modulo>::pages.<nombre>` o `<modulo>::components.<nombre>`.
- **Migraciones:** dentro del módulo. Una migración mezclada en `develop` no se edita; se crea otra.
- **Llaves:** UUIDv7 en todo modelo (`HasUuids`, `foreignUuid`, `default uuidv7()`); los ids del cliente se buscan con `FindByKey` ([ADR-0014](docs/decisiones/0014-uuidv7-como-llave-primaria.md)).
- **Enums** PHP respaldados por string. Eventos con `timestamptz`; horas de sesión con `time`. Zona `America/Ciudad_Juarez`.
- **Catálogos:** soft deletes + `is_active`; nunca borrado físico. Bitácora: nunca se edita ni se borra.
- **UI:** componentes MaryUI; Tailwind utilitario; tokens de tema en [DESIGN.md](DESIGN.md). Sin CSS a mano salvo tokens.
- **Formato:** Pint antes de cada commit; CI lo verifica.

## 6. Pruebas (obligatorio)

TDD: escribe la prueba (rojo), la implementación mínima (verde), refactoriza. Pest 5.

- **Unit:** reglas, Actions, Queries, scorers, plantillas de importación.
- **Feature:** componentes Livewire, jobs, policies y alcance, transiciones de estado, seeders, restricciones de BD.
- **Browser:** un flujo crítico por rol por fase.
- **Arch:** dependencias entre módulos y convenciones.
- Cada regla `HC-xx` / `SC-xx` tiene una prueba que la nombra. Cada pantalla tiene una prueba negativa de alcance (otro rol u otro campus no ve ni edita).
- Prohibido `skip` o marcar incompleto para pasar CI. Cobertura mínima por módulo: 80 %.
- Nunca afirmes que algo pasa sin haber corrido la suite y mostrado el resultado.

Detalle: `docs/arquitectura/testing.md`.

## 7. Git y PRs

- `feature/*` → `develop` por PR. `develop` → `main` por PR cuando la integración está probada. Ambas ramas están protegidas: **nunca push directo**.
- Commits convencionales en inglés con el módulo como alcance: `feat(scheduling): add room suggestion query`, `test(staffing): cover HC-16`.
- Un PR por tarea del plan, de preferencia menos de 400 líneas netas, con `Closes #<issue>`; sigue la plantilla de PR.
- Revisor cruzado obligatorio y CI verde obligatorio.
- Al cerrar una tarea: márcala en `docs/plan/fase-N.md` con el número de PR. Al cerrar una fase: actualiza [PLAN.md](PLAN.md).

Detalle: [CONTRIBUTING.md](CONTRIBUTING.md).

## 8. Reglas duras

1. No inventes reglas de negocio. Si no está en `docs/dominio/reglas-de-negocio.md`, pregunta y documenta.
2. No cambies el modelo de datos sin actualizar `docs/dominio/modelo-de-datos.md` en el mismo PR.
3. No toques módulos de otra persona sin acordarlo en el issue.
4. No borres registros de catálogo ni de bitácora. Desactiva.
5. No metas lógica de proceso en modelos ni consultas en Blade.
6. No agregues dependencias sin una nota en `docs/decisiones/`.
7. No compares Nexus con otros proyectos; decide por lo que Nexus necesita.
8. No uses SQLite para pruebas.
9. Los archivos exportados del SII tienen datos personales: viven en `storage/app/imports/` (ignorado por git) y **nunca** se suben al repositorio ni a fixtures sin anonimizar.
10. No marques una fase como hecha sin que todas sus tareas tengan PR mezclado en `develop`.

## 9. Dónde está cada cosa

| Qué | Dónde |
|---|---|
| Módulos | `app/Modules/<Modulo>/` |
| Layout y navegación | `resources/views/layouts/app.blade.php`, `app/Modules/Core/Livewire/components`, `app/Modules/Core/Navigation` (entradas por módulo en `navigation()` de su provider) |
| Pruebas | `tests/Unit/<Modulo>`, `tests/Feature/<Modulo>`, `tests/Browser/<Modulo>`, `tests/Arch` |
| Fixtures de importación | `tests/Fixtures/imports/` (anonimizados) |
| Docker | `Dockerfile`, `compose.yaml`, `compose.override.yaml`, `docker/` |
| CI | `.github/workflows/ci.yml` |
| Plantillas de issues y PR | `.github/` |
| Skills del proyecto | `.claude/skills/` |
| Servicio del motor (R2) | `services/solver/` |

## 10. Guías generadas por Laravel Boost

@AGENTS.md
