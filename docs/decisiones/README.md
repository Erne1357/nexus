# Registro de decisiones

Decisiones de arquitectura y de alcance con su contexto, para que nadie las reabra sin saber por qué se tomaron. Una decisión nueva = un archivo nuevo con el siguiente número; una decisión revertida se marca "Reemplazada por ADR-XXXX", no se borra.

| ADR | Decisión | Estado | Fecha |
|---|---|---|---|
| [0001](0001-monolito-modular.md) | Monolito modular sin hexagonal ni DDD | Aceptada | 2026-10-26 |
| [0002](0002-pest-4-y-postgres-de-pruebas.md) | Pest en tres niveles y PostgreSQL para pruebas | Aceptada; versión de Pest reemplazada por [0011](0011-pest-5.md) | 2026-10-26 |
| [0003](0003-livewire-maryui.md) | Livewire 4 MFC con MaryUI y daisyUI | Aceptada | 2026-10-26 |
| [0004](0004-fortify-username-y-alcances.md) | Fortify con username; roles de spatie más alcances propios | Aceptada; roles y alcances reemplazados por [0018](0018-permisos-por-puesto-y-perfil-activo.md) | 2026-10-26 |
| [0005](0005-importacion-por-archivos.md) | Carga inicial por importación de archivos del SII | Aceptada | 2026-10-26 |
| [0006](0006-cupos-por-carrera.md) | Cupos por carrera en lugar de paralelos | Aceptada | 2026-10-26 |
| [0007](0007-motor-en-dos-pasos.md) | Motor de horarios: heurística PHP y luego OR-Tools CP-SAT en Python | Aceptada | 2026-10-26 |
| [0008](0008-cierre-por-departamento.md) | Unidad de cierre = departamento en un campus; visto bueno de Subdirección configurable | Aceptada | 2026-10-26 |
| [0009](0009-modalidad-como-perfil-de-campus.md) | Modalidad como perfil de flujo del campus | Aceptada | 2026-10-26 |
| [0010](0010-alcance-y-fecha-de-r1.md) | R1 sin motor de sugerencia, fecha 4 de diciembre de 2026 | Aceptada | 2026-10-26 |
| [0011](0011-pest-5.md) | Pest 5 (PHPUnit 13) para todas las pruebas; reemplaza la versión de Pest de 0002 | Aceptada | 2026-10-27 |
| [0012](0012-dependencias-base.md) | Dependencias base de la fundación: activitylog, openspout y Boost | Aceptada | 2026-10-27 |
| [0013](0013-despliegue-on-premise-itcj.md) | Despliegue on-premise en el ITCJ: HTTPS con Caddy, `.env` con permisos restringidos, `pg_dump` diario con retención | Aceptada; puntos por confirmar con el ITCJ | 2026-10-27 |
| [0014](0014-uuidv7-como-llave-primaria.md) | UUIDv7 como llave primaria en todo modelo de Nexus; se editaron una sola vez las migraciones de la fase 0 | Aceptada | 2026-10-27 |
| [0015](0015-identidad-visual.md) | Identidad visual: credencial con áreas, menú que se transforma, paleta institucional, Atkinson Hyperlegible y la liebre como marca | Aceptada | 2026-10-29 |
| [0016](0016-btree-gist-para-exclusiones-por-fecha.md) | Extensión `btree_gist` para restricciones de exclusión por rango de fechas (un jefe a la vez) | Aceptada | 2026-10-29 |
| [0017](0017-catalog-validation-antes-de-academic-load.md) | CatalogValidation a la izquierda de AcademicLoad: la carga lee el estado de las revisiones de catálogo (HC-14) | Aceptada | 2026-11-01 |
| [0018](0018-permisos-por-puesto-y-perfil-activo.md) | Permisos por módulo, puestos en código, nombramientos, árbol de unidades y perfil activo; sale spatie/permission | Aceptada | 2026-11-02 |
| [0019](0019-pandas-para-perfilar-exportaciones-del-sii.md) | pandas en un contenedor sin red para perfilar las exportaciones del SII; solo herramienta de análisis, no dependencia de la app | Aceptada | 2026-11-05 |
| [0020](0020-staffing-a-la-derecha-de-scheduling.md) | Staffing a la derecha de Scheduling (`Faculty --> Scheduling --> Staffing`): ranking, asignación, vista docente y solicitudes de cambio leen grupos y el validador; `change_requests` pasa a Staffing | Aceptada | 2026-11-07 |
| [0022](0022-esquemas-de-postgresql-por-modulo.md) | Un esquema de PostgreSQL por módulo (`core`, `academic`, `scheduling`, …) con `search_path`; `public` para la plomería de Laravel; el SII original en `sii_archivo` y la verificación en `sii_verificacion`; las tablas existentes se mueven al cerrar la fase 4 | Aceptada | 2026-11-10 |
| [0023](0023-sin-dialogos-nativos-del-navegador.md) | Sin diálogos nativos del navegador (`confirm()`, `alert()`, `prompt()`, `wire:confirm`): modal de MaryUI para lo corto, drawer para lo largo (el preferido); solo queda el aviso de `beforeunload`; el reemplazo va en #374 | Aceptada | 2026-11-10 |

Plantilla: Contexto · Decisión · Consecuencias · Alternativas descartadas.
